<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduleStatus;
use App\Enums\TaskStatus;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

/**
 * CÔNG VIỆC → LỊCH LÀM VIỆC. Lịch làm việc không nhập tay: mỗi công việc có
 * ngày bắt đầu tự có một lịch (ScheduleEntry có from_task) mang đúng thông tin
 * của công việc, và lịch đó được cập nhật mỗi khi công việc đổi.
 *
 *   Công việc                         Lịch
 *   tên, mô tả                     →  tên lịch, ghi chú
 *   dự án / khách hàng             →  dự án / khách hàng
 *   ngày bắt đầu → hạn hoàn thành  →  từ ngày … đến ngày (thiếu hạn: một ngày)
 *   cả ngày / từ giờ – đến giờ     →  giờ của lịch
 *   người phụ trách + ê-kíp        →  ê-kíp (người phụ trách đứng đầu)
 *   loại lịch, địa điểm, thiết bị  →  như vậy
 *   Hoàn thành / Đã huỷ / còn lại  →  Đã xong / Đã huỷ / Đã chốt (hoặc Dự kiến)
 *
 * Công việc không có ngày bắt đầu (hoặc kéo dài quá 2 tháng) thì không lên lịch.
 * Task::saved gọi sync(); Task::deleting gọi forget().
 */
class TaskSchedule
{
    /** Một lịch không kéo dài quá số ngày này (cùng giới hạn với ScheduleManager). */
    public const MAX_DAYS = 62;

    /** Các cột của công việc quyết định lịch: đổi cột khác thì không cần đụng tới lịch. */
    private const RELEVANT = [
        'name', 'description', 'project_id', 'customer_id', 'assignee_id', 'status', 'start_date', 'due_date',
        'schedule_type_id', 'is_all_day', 'start_time', 'end_time', 'crew_ids', 'location', 'equipment',
        'external_crew', 'is_tentative',
    ];

    private static int $paused = 0;

    public function __construct(private readonly ScheduleManager $manager) {}

    /**
     * Chạy $callback mà không đồng bộ công việc → lịch. Dùng khi chính lịch
     * đang ghi ngược về công việc (ScheduleTasks), để hai chiều không gọi nhau mãi.
     */
    public static function withoutSync(Closure $callback): mixed
    {
        self::$paused++;

        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    /**
     * Tạo, cập nhật hoặc gỡ lịch của công việc cho khớp với công việc.
     */
    public function sync(Task $task, bool $notify = true, bool $force = false): ?ScheduleEntry
    {
        if (self::$paused > 0) {
            return null;
        }

        $entry = $task->schedule()->first();

        if (! $force && $entry !== null && ! $task->wasRecentlyCreated && ! $task->wasChanged(self::RELEVANT)) {
            return $entry;
        }

        $attributes = $this->attributes($task, $entry);
        $by = auth()->user();

        if ($attributes === null) {
            // Không còn lên lịch. Lịch đã ghi giờ công thì giữ lại để tra cứu.
            if ($entry !== null && ! $entry->timesheets()->exists()) {
                $this->manager->discard($entry, $by instanceof User ? $by : null);
            }

            return null;
        }

        // Công việc đã huỷ mà chưa từng lên lịch thì không cần tạo lịch "Đã huỷ".
        if ($entry === null && $task->status === TaskStatus::Cancelled) {
            return null;
        }

        return $this->manager->saveForTask($task, $entry, $attributes, $task->crewIds(), $by instanceof User ? $by : null, $notify);
    }

    /**
     * Công việc sắp bị xoá: bỏ lịch của nó (lịch đã ghi giờ công thì giữ lại).
     */
    public function forget(Task $task): void
    {
        if (self::$paused > 0) {
            return;
        }

        $entry = $task->schedule()->first();

        if ($entry !== null && ! $entry->timesheets()->exists()) {
            $by = auth()->user();

            $this->manager->discard($entry, $by instanceof User ? $by : null);
        }
    }

    /**
     * Khoảng thời gian công việc chiếm trên lịch; null khi công việc không lên lịch.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, is_all_day: bool}|null
     */
    public function span(Task $task): ?array
    {
        if ($task->start_date === null) {
            return null;
        }

        $first = CarbonImmutable::parse($task->start_date)->startOfDay();
        $last = $task->due_date !== null && CarbonImmutable::parse($task->due_date)->startOfDay()->gte($first)
            ? CarbonImmutable::parse($task->due_date)->startOfDay()
            : $first;

        if ($first->diffInDays($last) > self::MAX_DAYS) {
            return null;
        }

        if (! $task->is_all_day && $this->isTime($task->start_time) && $this->isTime($task->end_time)) {
            $startsAt = $first->setTimeFromTimeString($task->start_time);
            $endsAt = $last->setTimeFromTimeString($task->end_time);

            if ($endsAt->gt($startsAt)) {
                return ['starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_all_day' => false];
            }
        }

        // Không có giờ hợp lệ thì coi là cả ngày.
        return ['starts_at' => $first, 'ends_at' => $last->endOfDay()->startOfSecond(), 'is_all_day' => true];
    }

    /**
     * Nối dữ liệu cũ khi nâng cấp:
     *   1. lịch do hệ thống tạo công việc cho (bản trước) trở thành lịch của
     *      chính công việc đó, và thông tin lịch được chép về công việc;
     *   2. lịch của công việc nhận lại trạng thái hiện tại của công việc;
     *   3. công việc có ngày bắt đầu mà chưa có lịch thì được tạo lịch.
     * Không gửi thông báo, không ghi nhật ký.
     *
     * @return array{linked: int, created: int}
     */
    public function backfill(): array
    {
        $linked = 0;
        $created = 0;

        AuditLogger::withoutLogging(function () use (&$linked, &$created): void {
            ScheduleEntry::query()
                ->where('from_task', false)
                ->whereNotNull('task_id')
                ->whereHas('type', fn ($query) => $query->where('is_leave', false))
                ->whereHas('task', fn ($query) => $query->where('from_schedule', true))
                ->with(['task', 'crew'])
                ->orderBy('id')
                ->each(function (ScheduleEntry $entry) use (&$linked): void {
                    $task = $entry->task;

                    // Công việc dùng chung nhiều lịch: không lịch nào là "của chính nó".
                    if ($task === null || $task->schedule()->exists() || $task->scheduleEntries()->count() !== 1) {
                        return;
                    }

                    ScheduleEntry::query()->whereKey($entry->getKey())->update(['from_task' => true]);
                    ScheduleTasks::mirror($task, $entry, $entry->crew->pluck('id')->all());

                    // Ghi chú của lịch thành mô tả công việc, để lần đồng bộ sau không làm mất.
                    if (blank($task->description)) {
                        $task->description = $entry->notes;
                    }

                    $task->saveQuietly();

                    $linked++;
                });

            // Từ đây công việc là nguồn: lịch của công việc nhận lại trạng thái của công việc
            // (ví dụ công việc đã "Hoàn thành" mà lịch còn "Đã chốt").
            Task::query()
                ->whereHas('schedule')
                ->orderBy('id')
                ->each(fn (Task $task) => $this->sync($task, notify: false, force: true));

            Task::query()
                ->whereNotNull('start_date')
                ->whereDoesntHave('schedule')
                ->orderBy('id')
                ->each(function (Task $task) use (&$created): void {
                    if ($this->sync($task, notify: false, force: true) !== null) {
                        $created++;
                    }
                });
        });

        return ['linked' => $linked, 'created' => $created];
    }

    /**
     * Các cột của lịch suy ra từ công việc; null khi công việc không lên lịch.
     *
     * @return array<string, mixed>|null
     */
    private function attributes(Task $task, ?ScheduleEntry $entry): ?array
    {
        $span = $this->span($task);
        $typeId = $this->typeId($task, $entry);

        if ($span === null || $typeId === null) {
            return null;
        }

        return [
            'title' => $task->name,
            'schedule_type_id' => $typeId,
            'project_id' => $task->project_id,
            // Có dự án thì lịch tự lấy khách hàng của dự án.
            'customer_id' => $task->project_id !== null ? $entry?->customer_id : $task->customer_id,
            'starts_at' => $span['starts_at'],
            'ends_at' => $span['ends_at'],
            'is_all_day' => $span['is_all_day'],
            'location' => $task->location,
            'equipment' => $task->equipment,
            'external_crew' => $task->external_crew,
            'notes' => filled($task->description) ? Str::limit((string) $task->description, 1900, '…') : null,
            'status' => match ($task->status) {
                TaskStatus::Done => ScheduleStatus::Done,
                TaskStatus::Cancelled => ScheduleStatus::Cancelled,
                default => $task->is_tentative ? ScheduleStatus::Tentative : ScheduleStatus::Confirmed,
            },
        ];
    }

    /**
     * Loại lịch của công việc; chưa chọn (hoặc loại đã bị xoá / là loại nghỉ)
     * thì giữ loại đang dùng trên lịch, rồi mới tới loại mặc định đầu danh sách.
     */
    private function typeId(Task $task, ?ScheduleEntry $entry): ?int
    {
        $work = fn () => ScheduleType::query()->where('is_leave', false);

        if ($task->schedule_type_id !== null && $work()->whereKey($task->schedule_type_id)->exists()) {
            return (int) $task->schedule_type_id;
        }

        if ($entry !== null && $work()->whereKey($entry->schedule_type_id)->exists()) {
            return (int) $entry->schedule_type_id;
        }

        $default = $work()->active()->ordered()->value('id') ?? $work()->ordered()->value('id');

        return $default !== null ? (int) $default : null;
    }

    private function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
