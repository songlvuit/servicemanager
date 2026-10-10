<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduleStatus;
use App\Exceptions\WorkflowException;
use App\Models\ScheduleEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ghi giờ công cho cả ê-kíp từ một lịch đã làm xong: mỗi người, mỗi ngày của
 * lịch một dòng giờ công (giờ × đơn giá giờ của từng người), ghi vào công việc
 * của lịch nên thành chi phí nhân sự của công việc đó.
 *
 * Gọi lại nhiều lần không ghi trùng: người / ngày nào đã ghi thì bỏ qua.
 */
class ScheduleTimesheets
{
    public function __construct(private readonly ScheduleTasks $tasks) {}

    /** Số giờ mặc định của một ngày làm việc trọn ngày. */
    public const FULL_DAY_HOURS = 8;

    /**
     * Số giờ gợi ý cho mỗi ngày của lịch: thời lượng thật của lịch trong một
     * ngày, hoặc 8 giờ với lịch cả ngày / nhiều ngày.
     */
    public function suggestedHours(ScheduleEntry $entry): string
    {
        if ($entry->is_all_day || $entry->isMultiDay()) {
            return (string) self::FULL_DAY_HOURS;
        }

        $hours = round($entry->starts_at->diffInMinutes($entry->ends_at) / 60, 2);

        return Decimal::trimZeros((string) min(24, max(0.25, $hours))) ?? '0';
    }

    /**
     * @return int Số dòng giờ công vừa tạo.
     */
    public function log(ScheduleEntry $entry, User $by, int|float|string $hoursPerDay): int
    {
        if ($by->cannot('logTimesheets', $entry)) {
            throw new AuthorizationException('Bạn không có quyền ghi giờ công từ lịch.');
        }

        $entry->load(['crew', 'task', 'type']);

        if ($entry->isLeave()) {
            throw new WorkflowException('Lịch nghỉ / bận không ghi giờ công.');
        }

        if ($entry->task?->isCancelled()) {
            throw new WorkflowException('Công việc của lịch đã huỷ nên không nhận thêm giờ công.');
        }

        if ($entry->status === ScheduleStatus::Cancelled) {
            throw new WorkflowException('Lịch đã huỷ nên không ghi giờ công.');
        }

        if ($entry->starts_at->isFuture()) {
            throw new WorkflowException('Lịch chưa diễn ra nên chưa ghi giờ công được.');
        }

        if ($entry->crew->isEmpty()) {
            throw new WorkflowException('Lịch chưa có ai trong ê-kíp.');
        }

        $hours = Decimal::round($hoursPerDay);

        if (! Decimal::isPositive($hours) || Decimal::compare($hours, 24) > 0) {
            throw new WorkflowException('Số giờ mỗi ngày phải lớn hơn 0 và không quá 24.');
        }

        return AuditLogger::transactionAs($by, function () use ($entry, $hours): int {
            $entry = ScheduleEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail()->load(['crew']);

            $logged = $entry->timesheets()
                ->get(['timesheets.id', 'timesheets.user_id', 'timesheets.work_date'])
                ->map(fn (Timesheet $timesheet): string => $timesheet->user_id.'|'.$timesheet->work_date->toDateString())
                ->all();

            $created = 0;
            $lastDay = CarbonImmutable::parse($entry->ends_at)->startOfDay();

            // Không ghi cho những ngày chưa tới của lịch nhiều ngày.
            if ($lastDay->isFuture()) {
                $lastDay = CarbonImmutable::today();
            }

            for ($day = CarbonImmutable::parse($entry->starts_at)->startOfDay(); $day->lte($lastDay); $day = $day->addDay()) {
                foreach ($entry->crew as $user) {
                    if (in_array($user->getKey().'|'.$day->toDateString(), $logged, true)) {
                        continue;
                    }

                    $timesheet = Timesheet::create([
                        'user_id' => $user->getKey(),
                        // Giờ công vào luôn công việc của lịch để tính chi phí của công việc.
                        'task_id' => $entry->task_id,
                        'work_date' => $day->toDateString(),
                        'hours' => $hours,
                        'description' => 'Theo lịch: '.$entry->title,
                    ]);

                    $entry->timesheets()->attach($timesheet->getKey());
                    $created++;
                }
            }

            $entry->forceFill([
                'timesheets_logged_at' => now(),
                'status' => ScheduleStatus::Done,
            ])->save();

            $this->tasks->completed($entry);

            return $created;
        });
    }
}
