<?php

namespace App\Services\Tasks;

use App\Models\ScheduleEntry;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Scheduling\TaskSchedule;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CÔNG CỦA NHÂN SỰ TỰ SINH THEO PHÂN CÔNG. Người quản lý không phải chấm công
 * tay: mỗi người được phân công vào một công việc (người phụ trách + ê-kíp)
 * tự có một dòng công cho từng ngày của công việc, nên chi phí nhân sự — và
 * chi phí của công việc, của hợp đồng — có ngay khi phân công.
 *
 *   số buổi mỗi ngày
 *     việc có giờ   = thời lượng trong ngày ÷ job.session_hours, làm tròn, ít nhất 1 buổi
 *     việc cả ngày  = job.sessions_per_day
 *   chi phí một dòng = số buổi × đơn giá buổi của người đó lúc được phân công
 *
 * Công việc không có ngày bắt đầu (không lên lịch), đã huỷ, hoặc là "việc dài
 * ngày" (cả ngày, kéo dài hơn ScheduleEntry::LONG_DAYS ngày — người làm vẫn
 * nhận việc khác trong lúc đó) thì KHÔNG tự tính công: nhập tay ở tab Chấm công.
 *
 * Dòng tự sinh (is_auto) đi theo công việc: đổi ngày, giờ, người thì công đổi
 * theo; huỷ / xoá công việc thì mất. Dòng nhập tay và dòng tự sinh đã được sửa
 * tay không bao giờ bị ghi đè: người / ngày nào đã có dòng nhập tay thì hệ
 * thống không sinh thêm (muốn một người không tính công ngày nào đó: sửa số
 * buổi của dòng ấy về 0).
 *
 * Task::saved gọi sync(); Task::deleting gọi forget().
 */
class TaskLabor
{
    public const DESCRIPTION = 'Theo phân công';

    /** Các cột của công việc quyết định công: đổi cột khác thì không cần tính lại. */
    public const RELEVANT = ['assignee_id', 'crew_ids', 'status', 'start_date', 'due_date', 'is_all_day', 'start_time', 'end_time'];

    public function __construct(
        private readonly TaskSchedule $schedule,
        private readonly TaskFinancials $financials,
    ) {}

    /**
     * Công theo phân công hiện tại của công việc.
     *
     * @return array<string, array{user_id: int, date: string, sessions: string}> khoá "user_id|Y-m-d"
     */
    public function plan(Task $task): array
    {
        if (! $task->exists || $task->isCancelled()) {
            return [];
        }

        $span = $this->schedule->span($task);

        if ($span === null || ScheduleEntry::isLongSpan($span['starts_at'], $span['ends_at'], $span['is_all_day'])) {
            return [];
        }

        $userIds = User::query()->whereKey($task->crewIds())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $sessions = $this->sessionsPerDay($span['starts_at'], $span['ends_at'], $span['is_all_day']);
        $last = $span['ends_at']->startOfDay();
        $plan = [];

        for ($day = $span['starts_at']->startOfDay(); $day->lte($last); $day = $day->addDay()) {
            foreach ($userIds as $userId) {
                $plan[$userId.'|'.$day->toDateString()] = [
                    'user_id' => $userId,
                    'date' => $day->toDateString(),
                    'sessions' => $sessions,
                ];
            }
        }

        return $plan;
    }

    /**
     * Số buổi (công) của mỗi người trong MỘT ngày của công việc.
     */
    public function sessionsPerDay(CarbonImmutable $startsAt, CarbonImmutable $endsAt, bool $allDay): string
    {
        $fullDay = max(1, (int) config('job.sessions_per_day', 2));

        if ($allDay) {
            return Decimal::round($fullDay);
        }

        // Việc nhiều ngày có giờ: mỗi ngày làm từ giờ bắt đầu tới giờ kết thúc.
        $minutes = $startsAt->isSameDay($endsAt)
            ? $startsAt->diffInMinutes($endsAt)
            : $startsAt->diffInMinutes($startsAt->setTimeFrom($endsAt), false);

        if ($minutes <= 0) {
            return Decimal::round($fullDay);
        }

        $perSession = max(1, (int) config('job.session_hours', 4)) * 60;

        return Decimal::round(min(Timesheet::MAX_SESSIONS, max(1, (int) round($minutes / $perSession))));
    }

    /**
     * Đưa các dòng công tự sinh của công việc về đúng phân công hiện tại.
     *
     * @return array{created: int, updated: int, removed: int}
     */
    public function sync(Task $task): array
    {
        $result = ['created' => 0, 'updated' => 0, 'removed' => 0];
        $plan = $this->plan($task);

        $rows = Timesheet::query()
            ->where('task_id', $task->getKey())
            ->orderBy('id')
            ->get(['id', 'user_id', 'work_date', 'sessions', 'session_rate', 'is_auto']);

        if ($plan === [] && $rows->where('is_auto', true)->isEmpty()) {
            return $result;
        }

        $key = fn (Timesheet $row): string => $row->user_id.'|'.$row->work_date->toDateString();
        // Người / ngày đã có dòng nhập tay: không sinh, không giữ dòng tự sinh trùng.
        $manual = $rows->where('is_auto', false)->mapWithKeys(fn (Timesheet $row): array => [$key($row) => true])->all();
        $kept = [];
        $remove = [];

        DB::transaction(function () use ($task, $plan, $rows, $key, $manual, &$kept, &$remove, &$result): void {
            foreach ($rows->where('is_auto', true) as $row) {
                $rowKey = $key($row);

                if (! isset($plan[$rowKey]) || isset($manual[$rowKey]) || isset($kept[$rowKey])) {
                    $remove[] = $row->getKey();

                    continue;
                }

                $kept[$rowKey] = true;

                if (Decimal::compare($row->sessions, $plan[$rowKey]['sessions']) !== 0) {
                    // Đổi công của nhân sự việc: số buổi đổi theo, đơn giá đã chụp giữ nguyên.
                    Timesheet::query()->whereKey($row->getKey())->update([
                        'sessions' => $plan[$rowKey]['sessions'],
                        'cost' => Decimal::mul($plan[$rowKey]['sessions'], $row->session_rate),
                        'updated_at' => now(),
                    ]);
                    $result['updated']++;
                }
            }

            if ($remove !== []) {
                Timesheet::query()->whereKey($remove)->delete();
                $result['removed'] = count($remove);
            }

            $missing = array_diff_key($plan, $kept, $manual);

            if ($missing !== []) {
                $rates = User::query()->whereKey(array_column($missing, 'user_id'))->pluck('session_rate', 'id');
                $now = now();

                Timesheet::query()->insert(array_values(array_map(fn (array $line): array => [
                    'user_id' => $line['user_id'],
                    'task_id' => $task->getKey(),
                    'work_date' => $line['date'],
                    'sessions' => $line['sessions'],
                    'session_rate' => $rates[$line['user_id']] ?? 0,
                    'cost' => Decimal::mul($line['sessions'], $rates[$line['user_id']] ?? 0),
                    'description' => self::DESCRIPTION,
                    'is_auto' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $missing)));
                $result['created'] = count($missing);
            }
        });

        if (array_sum($result) > 0) {
            $this->refresh($task);
        }

        return $result;
    }

    /**
     * Công việc sắp bị xoá: bỏ các dòng công tự sinh của nó (dòng nhập tay
     * được giữ lại, thành chi phí chung).
     */
    public function forget(Task $task): void
    {
        Timesheet::query()->where('task_id', $task->getKey())->where('is_auto', true)->delete();
    }

    /**
     * Đơn giá buổi của một người vừa đổi: công tự sinh từ hôm nay trở đi tính
     * theo đơn giá mới; công của những ngày đã qua giữ nguyên.
     */
    public function reprice(User $user): int
    {
        $rows = Timesheet::query()
            ->where('user_id', $user->getKey())
            ->where('is_auto', true)
            ->whereDate('work_date', '>=', today())
            ->get(['id', 'task_id', 'sessions']);

        foreach ($rows as $row) {
            Timesheet::query()->whereKey($row->getKey())->update([
                'session_rate' => $user->session_rate,
                'cost' => Decimal::mul($row->sessions, $user->session_rate),
                'updated_at' => now(),
            ]);
        }

        foreach (Task::query()->whereKey($rows->pluck('task_id')->filter()->unique()->all())->get() as $task) {
            $this->financials->recalculate($task);
        }

        return $rows->count();
    }

    /**
     * Sinh công cho mọi công việc đang có lịch (dùng khi nâng cấp). Người /
     * ngày đã chấm công từ trước thì giữ nguyên.
     *
     * @return int Số dòng công vừa sinh.
     */
    public function backfill(): int
    {
        $created = 0;

        Task::query()
            ->whereNotNull('start_date')
            ->orderBy('id')
            ->each(function (Task $task) use (&$created): void {
                $created += $this->sync($task)['created'];
            });

        return $created;
    }

    /**
     * Tổng công của công việc và các công việc con của nó (cùng phạm vi với chi phí của công việc).
     *
     * @return array{sessions: string, cost: string, people: int}
     */
    public function summary(Task $task): array
    {
        $row = Timesheet::query()
            ->whereIn('task_id', [$task->getKey(), ...$task->descendantIds()])
            ->toBase()
            ->selectRaw('COALESCE(SUM(sessions), 0) as sessions, COALESCE(SUM(cost), 0) as cost, COUNT(DISTINCT CASE WHEN sessions > 0 THEN user_id END) as people')
            ->first();

        return [
            'sessions' => Decimal::round(is_numeric($row?->sessions) ? (string) $row->sessions : '0'),
            'cost' => Decimal::round(is_numeric($row?->cost) ? (string) $row->cost : '0'),
            'people' => (int) ($row?->people ?? 0),
        ];
    }

    /**
     * Các dòng công được ghi thẳng bằng query (không qua sự kiện của model),
     * nên tự cộng lại số buổi và chi phí của công việc ở đây.
     */
    private function refresh(Task $task): void
    {
        Task::query()->whereKey($task->getKey())->update([
            'actual_sessions' => (string) (Timesheet::query()->where('task_id', $task->getKey())->sum('sessions') ?: 0),
        ]);

        $this->financials->recalculate($task);
    }
}
