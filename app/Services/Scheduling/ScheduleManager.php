<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduleStatus;
use App\Exceptions\WorkflowException;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Mọi thao tác ghi lên lịch làm việc đi qua đây: kiểm tra quyền, kiểm tra
 * thời gian, lưu lịch cùng ê-kíp trong một transaction, ghi nhật ký và gửi
 * thông báo cho những người bị ảnh hưởng.
 *
 * Trên giao diện, lịch công việc KHÔNG được tạo hay sửa trực tiếp: nó là bản
 * chiếu của công việc, do TaskSchedule gọi saveForTask() mỗi khi công việc
 * đổi. save() / delete() còn dùng cho lịch nghỉ và cho dữ liệu nhập bằng mã
 * (seeder, tích hợp): khi đó công việc của lịch được tạo / cập nhật theo lịch
 * — xem ScheduleTasks.
 */
class ScheduleManager
{
    /** Công việc được chọn và ngân sách của nó đi kèm dữ liệu lịch nhưng không phải cột của lịch. */
    private const TASK_OPTIONS = ['task_id', 'task_budget'];

    public function __construct(
        private readonly ScheduleNotifier $notifier,
        private readonly ScheduleTasks $tasks,
    ) {}

    /**
     * Tạo mới ($entry = null) hoặc cập nhật một lịch.
     *
     * @param  array<string, mixed>  $attributes  Các cột của lịch (title, schedule_type_id, starts_at, ends_at…),
     *                                            kèm tuỳ chọn `task_id` (gắn vào công việc có sẵn) và `task_budget`
     *                                            (ngân sách của công việc — chỉ nhận từ người xem được chi phí nội bộ).
     * @param  list<int|string>  $crewIds  Người dùng trong ê-kíp.
     */
    public function save(?ScheduleEntry $entry, array $attributes, array $crewIds, User $by): ScheduleEntry
    {
        $isNew = $entry === null;
        $entry ??= new ScheduleEntry;

        if ($by->cannot($isNew ? 'create' : 'update', $isNew ? ScheduleEntry::class : $entry)) {
            throw new AuthorizationException('Bạn không có quyền xếp lịch.');
        }

        // Người chỉ được sửa lịch nghỉ của chính mình thì không đổi được loại lịch và người nghỉ.
        if (! $isNew && $by->cannot('schedules.update')) {
            $attributes['schedule_type_id'] = $entry->schedule_type_id;
            $attributes['customer_id'] = null;
            $crewIds = [$by->getKey()];
        }

        $taskOptions = Arr::only($attributes, self::TASK_OPTIONS);

        if ($by->cannot('internal_costs.view')) {
            unset($taskOptions['task_budget']);
        }

        return $this->persist($entry, Arr::except($attributes, self::TASK_OPTIONS), $crewIds, $by, taskOptions: $taskOptions);
    }

    /**
     * Lưu lịch CỦA MỘT CÔNG VIỆC theo dữ liệu của công việc đó (TaskSchedule gọi).
     * Quyền đã được xét ở công việc nên ở đây không xét lại quyền xếp lịch.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $crewIds
     */
    public function saveForTask(Task $task, ?ScheduleEntry $entry, array $attributes, array $crewIds, ?User $by, bool $notify = true): ScheduleEntry
    {
        return $this->persist($entry ?? new ScheduleEntry, $attributes, $crewIds, $by, mirrorOf: $task, notify: $notify);
    }

    /**
     * Bỏ lịch của một công việc khi công việc không còn lên lịch nữa (không
     * xét quyền, không đụng tới công việc).
     */
    public function discard(ScheduleEntry $entry, ?User $by): void
    {
        $this->remove($entry, $by);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $crewIds
     * @param  Task|null  $mirrorOf  Lịch là bản chiếu của công việc này: gắn thẳng vào công việc, không tạo / sửa ngược lại công việc.
     * @param  array<string, mixed>  $taskOptions
     */
    private function persist(ScheduleEntry $entry, array $attributes, array $crewIds, ?User $by, ?Task $mirrorOf = null, array $taskOptions = [], bool $notify = true): ScheduleEntry
    {
        $isNew = ! $entry->exists;

        $entry->fill($attributes);
        $this->validate($entry);

        if ($isNew) {
            $entry->created_by = $by?->getKey();
        }

        // Giữ thứ tự người dùng chọn: người đầu tiên là người phụ trách công việc của lịch.
        $crewIds = array_values(array_unique(array_map('intval', $crewIds)));
        $known = User::query()->whereIn('id', $crewIds)->pluck('id')->all();
        $crewIds = array_values(array_filter($crewIds, fn (int $id): bool => in_array($id, $known, true)));

        $before = $isNew ? null : $this->snapshot($entry->fresh() ?? $entry);

        AuditLogger::transactionAs($by, function () use ($entry, $crewIds, $before, $isNew, $taskOptions, $mirrorOf): void {
            if ($mirrorOf !== null) {
                $entry->task_id = $mirrorOf->getKey();
                $entry->from_task = true;
            } else {
                // Tạo / cập nhật công việc của lịch trước khi lưu, lúc còn biết cột nào vừa đổi.
                $this->tasks->sync($entry, $crewIds, $taskOptions);
            }

            $entry->save();

            $changes = $entry->crew()->sync($crewIds);

            if (! $isNew && ($changes['attached'] !== [] || $changes['detached'] !== [])) {
                $entry->load('crew');

                AuditLogger::record(
                    'updated',
                    $entry,
                    description: 'Cập nhật: Ê-kíp',
                    old: ['Ê-kíp' => $before['crewNames']],
                    new: ['Ê-kíp' => $entry->crew->pluck('name')->implode(', ')],
                );
            }
        });

        $entry->load(['crew', 'type']);

        if ($notify) {
            $this->notify($entry, $before, $by);
        }

        return $entry;
    }

    /**
     * Một người tự đăng ký lịch nghỉ cho chính mình.
     */
    public function registerLeave(User $user, CarbonInterface $start, CarbonInterface $end, bool $allDay = true, ?string $note = null): ScheduleEntry
    {
        $type = ScheduleType::leave();

        if ($type === null) {
            throw new WorkflowException('Chưa có loại lịch "nghỉ phép". Hãy nhờ quản trị viên tạo ở Hệ thống → Loại lịch.');
        }

        $entry = new ScheduleEntry([
            'title' => 'Nghỉ: '.$user->name,
            'schedule_type_id' => $type->getKey(),
            'starts_at' => $start,
            'ends_at' => $end,
            'is_all_day' => $allDay,
            'notes' => filled($note) ? trim((string) $note) : null,
            'status' => ScheduleStatus::Confirmed,
        ]);
        $entry->created_by = $user->getKey();

        $this->validate($entry);

        AuditLogger::transactionAs($user, function () use ($entry, $user): void {
            $entry->save();
            $entry->crew()->sync([$user->getKey()]);
        });

        $entry->load(['crew', 'type']);

        $this->notifier->leaveRegistered($entry, $user);

        return $entry;
    }

    public function delete(ScheduleEntry $entry, User $by): void
    {
        if ($by->cannot('delete', $entry)) {
            throw new AuthorizationException('Bạn không có quyền xoá lịch này.');
        }

        if ($entry->timesheets()->exists()) {
            throw new WorkflowException('Lịch này đã ghi giờ công nên không xoá được. Hãy chuyển sang trạng thái "Đã huỷ" nếu cần.');
        }

        $task = $entry->task;

        $this->remove($entry, $by, function () use ($task): void {
            if ($task !== null) {
                $this->tasks->discardIfUntouched($task);
            }
        });
    }

    /**
     * Xoá lịch và báo cho ê-kíp nếu lịch còn hiệu lực.
     */
    private function remove(ScheduleEntry $entry, ?User $by, ?Closure $afterDelete = null): void
    {
        $entry->load(['crew', 'type']);
        $crew = $entry->crew;
        $wasActive = $entry->status->occupiesTime() && $entry->ends_at->isFuture();

        AuditLogger::transactionAs($by, function () use ($entry, $afterDelete): void {
            $entry->delete();

            if ($afterDelete !== null) {
                $afterDelete();
            }
        });

        if ($wasActive && ! $entry->isLeave()) {
            $this->notifier->cancelled($entry, $crew->reject(fn (User $user): bool => $user->is($by)));
        }
    }

    private function validate(ScheduleEntry $entry): void
    {
        if (blank($entry->title)) {
            throw new WorkflowException('Vui lòng nhập tên lịch.');
        }

        if ($entry->starts_at === null || $entry->ends_at === null) {
            throw new WorkflowException('Vui lòng nhập thời gian bắt đầu và kết thúc.');
        }

        if ($entry->is_all_day) {
            if ($entry->ends_at->copy()->startOfDay()->lt($entry->starts_at->copy()->startOfDay())) {
                throw new WorkflowException('Ngày kết thúc phải từ ngày bắt đầu trở đi.');
            }
        } elseif ($entry->ends_at->lte($entry->starts_at)) {
            throw new WorkflowException('Giờ kết thúc phải sau giờ bắt đầu.');
        }

        if ($entry->starts_at->diffInDays($entry->ends_at) > 62) {
            throw new WorkflowException('Một lịch không kéo dài quá 2 tháng. Hãy tách thành nhiều lịch.');
        }

        if (ScheduleType::query()->whereKey($entry->schedule_type_id)->value('is_leave')) {
            $entry->customer_id = null;
        }
    }

    /**
     * @return array{crew: Collection<int, User>, crewNames: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, location: ?string, status: ScheduleStatus}
     */
    private function snapshot(ScheduleEntry $entry): array
    {
        $entry->load('crew');

        return [
            'crew' => $entry->crew,
            'crewNames' => $entry->crew->pluck('name')->implode(', '),
            'starts_at' => $entry->starts_at->toImmutable(),
            'ends_at' => $entry->ends_at->toImmutable(),
            'location' => $entry->location,
            'status' => $entry->status,
        ];
    }

    /**
     * Báo cho những người bị ảnh hưởng (không báo cho chính người thao tác).
     * Lịch nghỉ và lịch đã qua không tạo thông báo.
     *
     * @param  array{crew: Collection<int, User>, crewNames: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, location: ?string, status: ScheduleStatus}|null  $before
     */
    private function notify(ScheduleEntry $entry, ?array $before, ?User $by): void
    {
        if ($entry->isLeave() || $entry->ends_at->isPast()) {
            return;
        }

        $others = fn (Collection $users): Collection => $users->reject(fn (User $user): bool => $user->is($by))->values();
        $crew = $entry->crew;

        if ($before === null) {
            if ($entry->status->occupiesTime()) {
                $this->notifier->assigned($entry, $others($crew));
            }

            return;
        }

        $wasActive = $before['status']->occupiesTime();
        $isActive = $entry->status->occupiesTime();

        if ($wasActive && ! $isActive) {
            $this->notifier->cancelled($entry, $others($before['crew']));

            return;
        }

        if (! $isActive) {
            return;
        }

        $oldIds = $before['crew']->pluck('id');
        $added = $crew->reject(fn (User $user): bool => $wasActive && $oldIds->contains($user->getKey()));
        $removed = $before['crew']->reject(fn (User $user): bool => $crew->contains('id', $user->getKey()));
        $stayed = $crew->filter(fn (User $user): bool => $wasActive && $oldIds->contains($user->getKey()));

        $this->notifier->assigned($entry, $others($added));

        if ($wasActive) {
            $this->notifier->removed($entry, $others($removed));
        }

        $what = [];

        if (! $before['starts_at']->equalTo($entry->starts_at) || ! $before['ends_at']->equalTo($entry->ends_at)) {
            $what[] = 'Đổi thời gian';
        }

        if ((string) $before['location'] !== (string) $entry->location) {
            $what[] = 'Đổi địa điểm';
        }

        if ($what !== []) {
            $this->notifier->changed($entry, $others($stayed), implode(', ', $what));
        }
    }
}
