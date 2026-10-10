<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduleStatus;
use App\Enums\TaskStatus;
use App\Exceptions\WorkflowException;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Task;
use App\Services\Tasks\TaskLabor;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * LỊCH → CÔNG VIỆC, cho những lịch không sinh ra từ công việc: lịch nhập bằng
 * mã (seeder, tích hợp) và lịch có từ trước khi công việc là nguồn của lịch.
 * Trên giao diện, chiều chính là ngược lại — xem TaskSchedule.
 *
 *   - Lịch mới không chọn công việc  → tạo công việc cho lịch; lịch trở thành
 *     lịch của chính công việc đó (from_task) và hai bên luôn khớp nhau.
 *   - Lịch chọn một công việc có sẵn → chỉ gắn thêm vào công việc đó.
 *   - Sửa lịch của một công việc     → công việc đổi theo: tên, ngày giờ, loại
 *     lịch, ê-kíp, địa điểm, trạng thái.
 *   - Xoá lịch                       → công việc do lịch tạo ra bị xoá theo nếu
 *     chưa có gì gắn vào.
 *
 * Mọi lần ghi công việc ở đây đều tạm dừng chiều công việc → lịch.
 */
class ScheduleTasks
{
    /**
     * Gọi TRONG transaction, ngay TRƯỚC khi lưu lịch: $entry đã nhận dữ liệu
     * mới nhưng chưa lưu, nên còn biết cột nào vừa đổi.
     *
     * @param  list<int>  $crewIds  Ê-kíp mới của lịch.
     * @param  array{task_id?: mixed, task_budget?: mixed}  $options  Công việc được chọn và ngân sách (nếu người lưu được nhập).
     */
    public function sync(ScheduleEntry $entry, array $crewIds, array $options = []): void
    {
        $current = $entry->task_id !== null ? Task::query()->find($entry->task_id) : null;

        if ($this->isLeave($entry)) {
            $entry->task_id = null;
            $entry->from_task = false;

            if ($current !== null) {
                $this->discardIfUntouched($current, $entry->getKey());
            }

            return;
        }

        $requested = filled($options['task_id'] ?? null) ? (int) $options['task_id'] : null;

        if ($requested !== null && $requested !== $current?->getKey()) {
            $task = Task::query()->find($requested)
                ?? throw new WorkflowException('Công việc đã chọn không còn tồn tại.');

            // Lịch gắn thêm vào một công việc có sẵn: không phải lịch của chính công việc đó.
            $entry->task_id = $task->getKey();
            $entry->from_task = false;

            if ($current !== null) {
                $this->discardIfUntouched($current, $entry->getKey());
            }
        } elseif ($current === null) {
            $task = $this->createFor($entry, $crewIds);
            $entry->task_id = $task->getKey();
            $entry->from_task = true;
        } else {
            $task = $this->propagate($entry, $current, $crewIds);
        }

        if (array_key_exists('task_budget', $options)) {
            $budget = Decimal::round($options['task_budget'] ?? 0);

            if (Decimal::compare($budget, 0) < 0) {
                throw new WorkflowException('Ngân sách công việc không được âm.');
            }

            if (Decimal::compare($budget, $task->budget) !== 0) {
                $task->budget = $budget;
                TaskSchedule::withoutSync(fn () => $task->save());
            }
        }
    }

    /**
     * Sau khi xoá lịch: bỏ luôn công việc do lịch tạo ra nếu chưa có gì gắn vào.
     */
    public function discardIfUntouched(Task $task, int|string|null $exceptEntryId = null): bool
    {
        if (! $task->from_schedule || $task->hasBudget()) {
            return false;
        }

        if ($this->otherEntries($task, $exceptEntryId) || $task->children()->exists() || $task->hasCostDocuments()) {
            return false;
        }

        TaskSchedule::withoutSync(fn () => $task->delete());

        return true;
    }

    /**
     * Tạo công việc cho những lịch chưa có (lịch tạo trước khi có tính năng
     * này), và gắn công của nhân sự đã ghi từ lịch vào công việc đó.
     *
     * @return int Số công việc vừa tạo.
     */
    public function backfill(): int
    {
        $created = 0;

        ScheduleEntry::query()
            ->whereNull('task_id')
            ->whereHas('type', fn ($query) => $query->where('is_leave', false))
            ->with('crew')
            ->orderBy('id')
            ->each(function (ScheduleEntry $entry) use (&$created): void {
                DB::transaction(function () use ($entry): void {
                    AuditLogger::withoutLogging(function () use ($entry): void {
                        $task = $this->createFor($entry, $entry->crew->pluck('id')->all(), closePast: true);

                        // Ghi thẳng bằng query: không coi đây là một lần sửa lịch.
                        ScheduleEntry::query()->whereKey($entry->getKey())->update(['task_id' => $task->getKey(), 'from_task' => true]);

                        TaskSchedule::withoutSync(function () use ($entry, $task): void {
                            foreach ($entry->timesheets()->whereNull('timesheets.task_id')->get() as $timesheet) {
                                $timesheet->update(['task_id' => $task->getKey()]);
                            }
                        });

                        // Người / ngày đã có công chấm từ trước thì không tính thêm công tự sinh.
                        app(TaskLabor::class)->sync($task);
                    });
                });

                $created++;
            });

        return $created;
    }

    /**
     * @param  list<int>  $crewIds
     * @param  bool  $closePast  Lịch đã qua thì coi công việc là đã xong (chỉ dùng khi tạo bù).
     */
    private function createFor(ScheduleEntry $entry, array $crewIds, bool $closePast = false): Task
    {
        $status = $this->statusFor($entry->status) ?? TaskStatus::Todo;

        if ($closePast && $status === TaskStatus::Todo && $entry->ends_at->isPast()) {
            $status = TaskStatus::Done;
        }

        $task = new Task([
            'name' => $entry->title,
            'status' => $status,
            'description' => $entry->notes,
        ]);
        $task->from_schedule = true;
        static::mirror($task, $entry, $crewIds);

        TaskSchedule::withoutSync(fn () => $task->save());

        return $task;
    }

    /**
     * Đưa những gì vừa đổi trên lịch sang công việc của nó.
     *
     * @param  list<int>  $crewIds
     */
    private function propagate(ScheduleEntry $entry, Task $task, array $crewIds): Task
    {
        // Chỉ lịch của chính công việc mới ghi ngược về công việc; lịch gắn thêm thì không.
        if (! $entry->from_task) {
            return $task;
        }

        if ($entry->isDirty('title')) {
            $task->name = $entry->title;
        }

        if ($entry->isDirty('status')) {
            $status = $this->statusFor($entry->status);

            if ($status !== null) {
                $task->status = $status;
            } elseif (! $task->status->isOpen()) {
                // Lịch được dựng lại sau khi huỷ / xong: mở lại công việc.
                $task->status = TaskStatus::Todo;
            }
        }

        static::mirror($task, $entry, $crewIds);

        if ($task->isDirty()) {
            TaskSchedule::withoutSync(fn () => $task->save());
        }

        return $task;
    }

    /**
     * Chép thông tin lịch của $entry vào các cột lịch của công việc (chưa lưu):
     * ngày giờ, loại lịch, ê-kíp, địa điểm, thiết bị… để công việc và lịch khớp nhau.
     *
     * @param  list<int>  $crewIds  Ê-kíp của lịch; người đầu tiên là người phụ trách nếu người hiện tại không có trong ê-kíp.
     */
    public static function mirror(Task $task, ScheduleEntry $entry, array $crewIds): void
    {
        $crewIds = array_values(array_unique(array_map('intval', $crewIds)));

        if ($crewIds !== [] && ! in_array((int) $task->assignee_id, $crewIds, true)) {
            $task->assignee_id = $crewIds[0];
        }

        $allDay = (bool) $entry->is_all_day;

        $task->fill([
            'start_date' => $entry->starts_at->toDateString(),
            'due_date' => $entry->ends_at->toDateString(),
            'is_all_day' => $allDay,
            'start_time' => $allDay ? $task->start_time : $entry->starts_at->format('H:i'),
            'end_time' => $allDay ? $task->end_time : $entry->ends_at->format('H:i'),
            'schedule_type_id' => $entry->schedule_type_id,
            'crew_ids' => array_values(array_filter($crewIds, fn (int $id): bool => $id !== (int) $task->assignee_id)),
            'location' => $entry->location,
            'equipment' => $entry->equipment,
            'external_crew' => $entry->external_crew,
            'customer_id' => $task->contract_id !== null || $task->quotation_id !== null ? $task->customer_id : $entry->customer_id,
            'is_tentative' => $entry->status === ScheduleStatus::Tentative,
        ]);
    }

    /**
     * Lịch "Đã xong" / "Đã huỷ" quyết định trạng thái công việc; các trạng thái
     * khác của lịch không ép gì lên công việc.
     */
    private function statusFor(ScheduleStatus $status): ?TaskStatus
    {
        return match ($status) {
            ScheduleStatus::Done => TaskStatus::Done,
            ScheduleStatus::Cancelled => TaskStatus::Cancelled,
            default => null,
        };
    }

    private function otherEntries(Task $task, int|string|null $exceptEntryId): bool
    {
        return ScheduleEntry::query()
            ->where('task_id', $task->getKey())
            ->when($exceptEntryId !== null, fn ($query) => $query->whereKeyNot($exceptEntryId))
            ->exists();
    }

    private function isLeave(ScheduleEntry $entry): bool
    {
        return (bool) ScheduleType::query()->whereKey($entry->schedule_type_id)->value('is_leave');
    }
}
