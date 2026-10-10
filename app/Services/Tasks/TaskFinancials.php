<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Services\Contracts\ContractFinancials;
use Illuminate\Support\Facades\DB;

/**
 * Tiền của MỘT công việc: ngân sách do người quản lý nhập, còn chi phí được
 * tính từ các chứng từ gắn vào công việc và vào các công việc con của nó (đề
 * nghị chi, mua hàng, giờ công) — xem TaskCostCollector.
 *
 *   committed_cost = Σ chi phí đã cam kết (gồm cả phần đã chi)
 *   actual_cost    = Σ chi phí thực tế
 *
 * Gọi recalculate() mỗi khi một chứng từ của công việc thay đổi. Chi phí của
 * hợp đồng là tổng chi phí các công việc cấp cao nhất gắn với nó — xem
 * ContractFinancials.
 */
class TaskFinancials
{
    private static bool $silent = false;

    public function __construct(
        private readonly TaskCostCollector $costs,
        private readonly TaskNotifier $notifier,
        private readonly ContractFinancials $contracts,
    ) {}

    /**
     * Tính lại chi phí của công việc và của các công việc cha của nó (chi phí
     * của việc con được tính vào việc cha), rồi tới hợp đồng.
     */
    public function recalculate(Task $task): Task
    {
        $this->refresh($task);

        $seen = [$task->getKey()];
        $parentId = $task->parent_id;

        while ($parentId !== null && ! in_array($parentId, $seen, true)) {
            $parent = Task::query()->find($parentId);

            if ($parent === null) {
                break;
            }

            $this->refresh($parent);
            $seen[] = $parent->getKey();
            $parentId = $parent->parent_id;
        }

        // Chi phí của công việc là chi phí của hợp đồng mà nó thuộc về.
        $this->contracts->recalculateMany([$task->contract_id]);

        return $task;
    }

    /**
     * Tính lại mà không gửi thông báo "vượt ngân sách" — dùng khi chuyển đổi
     * dữ liệu hàng loạt (migration), lúc đó không có gì thật sự "vừa" vượt.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function silently(callable $callback): mixed
    {
        $previous = self::$silent;
        self::$silent = true;

        try {
            return $callback();
        } finally {
            self::$silent = $previous;
        }
    }

    /**
     * @param  iterable<int|string|null>  $taskIds  Bỏ qua id rỗng / không còn tồn tại.
     */
    public function recalculateMany(iterable $taskIds): void
    {
        $ids = array_values(array_unique(array_filter([...$taskIds])));

        foreach ($ids === [] ? [] : Task::query()->whereKey($ids)->get() as $task) {
            $this->recalculate($task);
        }
    }

    private function refresh(Task $task): void
    {
        $wasOver = $task->isOverBudget();
        $cost = $this->costs->totals($task);

        // Ghi thẳng bằng query để không kích hoạt lại các sự kiện của công việc.
        Task::query()->whereKey($task->getKey())->update([
            'committed_cost' => $cost['committed'],
            'actual_cost' => $cost['actual'],
        ]);

        $task->setAttribute('committed_cost', $cost['committed']);
        $task->setAttribute('actual_cost', $cost['actual']);
        $task->syncOriginalAttributes(['committed_cost', 'actual_cost']);

        // Rule 7: vừa chuyển sang vượt ngân sách thì báo cho người quản lý chi phí.
        if (! self::$silent && ! $wasOver && $task->isOverBudget()) {
            DB::afterCommit(fn () => $this->notifier->overBudget($task));
        }
    }
}
