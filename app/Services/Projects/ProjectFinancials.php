<?php

namespace App\Services\Projects;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Enums\TaskStatus;
use App\Models\BudgetItem;
use App\Models\Project;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Nơi duy nhất tính các số liệu tài chính và tiến độ của dự án (mục 17–19, 33–34).
 *
 *   budget          = Σ ngân sách kế hoạch
 *   committed_cost  = Σ chi phí đã cam kết (gồm cả phần đã chi)
 *   actual_cost     = Σ chi phí thực tế
 *   expected_profit = contract_value − budget
 *   actual_profit   = contract_value − actual_cost
 *   profit_margin   = actual_profit / contract_value × 100
 *
 * Gọi recalculate() mỗi khi ngân sách, chi phí, đơn mua hàng, chấm công, hóa
 * đơn hoặc thanh toán của dự án thay đổi.
 */
class ProjectFinancials
{
    public function __construct(
        private readonly ProjectCostCollector $costs,
        private readonly ProjectNotifier $notifier,
    ) {}

    public function recalculate(Project $project): Project
    {
        return DB::transaction(function () use ($project): Project {
            $project = Project::query()
                ->withTrashed()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $statusBefore = $project->costStatus();

            $costs = $this->costs->byCategory($project);
            $budget = '0';

            foreach ($project->budgetItems()->get() as $item) {
                $cost = $costs[$item->category->value] ?? ProjectCostCollector::EMPTY;

                // Ghi thẳng bằng query để không kích hoạt lại sự kiện của BudgetItem.
                BudgetItem::query()->whereKey($item->getKey())->update([
                    'committed_amount' => $cost['committed'],
                    'actual_amount' => $cost['actual'],
                    'variance' => Decimal::sub($item->planned_amount, $cost['actual']),
                ]);

                $budget = Decimal::add($budget, $item->planned_amount);
            }

            $committed = '0';
            $actual = '0';

            foreach ($costs as $cost) {
                $committed = Decimal::add($committed, $cost['committed']);
                $actual = Decimal::add($actual, $cost['actual']);
            }

            $labor = $costs[BudgetCategory::Personnel->value]['actual'] ?? '0';
            $supplier = $costs[BudgetCategory::Supplier->value]['actual'] ?? '0';
            $equipment = $costs[BudgetCategory::Equipment->value]['actual'] ?? '0';
            $other = Decimal::sub($actual, Decimal::add($labor, Decimal::add($supplier, $equipment)));

            $actualProfit = Decimal::sub($project->contract_value, $actual);
            $receivables = $this->costs->receivables($project);

            $project->forceFill([
                'budget' => $budget,
                'committed_cost' => $committed,
                'actual_cost' => $actual,
                'labor_cost' => $labor,
                'supplier_cost' => $supplier,
                'equipment_cost' => $equipment,
                'other_cost' => $other,
                'expected_profit' => Decimal::sub($project->contract_value, $budget),
                'actual_profit' => $actualProfit,
                // Cột DECIMAL(8,2): chặn trường hợp doanh thu gần 0 làm tỷ lệ vượt giới hạn.
                'profit_margin' => Decimal::clamp(
                    Decimal::ratio($actualProfit, $project->contract_value),
                    '-999999.99',
                    '999999.99',
                ),
                'invoiced_amount' => $receivables['invoiced'],
                'collected_amount' => $receivables['collected'],
            ])->saveQuietly();

            // Rule 7: vừa chuyển sang vượt ngân sách thì báo cho quản lý dự án và ban giám đốc.
            if ($project->costStatus() === CostStatus::Over && $statusBefore !== CostStatus::Over) {
                DB::afterCommit(fn () => $this->notifier->overBudget($project));
            }

            return $project;
        });
    }

    /**
     * Tiến độ dự án = trung bình tiến độ của các công việc chưa huỷ.
     */
    public function recalculateProgress(Project $project): Project
    {
        $progress = (int) round((float) $project->tasks()
            ->where('status', '!=', TaskStatus::Cancelled->value)
            ->avg('progress'));

        // Ghi thẳng bằng query: bản $project đang giữ trong bộ nhớ có thể đã cũ.
        Project::query()->withTrashed()->whereKey($project->getKey())->update(['progress' => $progress]);

        $project->setAttribute('progress', $progress);
        $project->syncOriginalAttribute('progress');

        return $project;
    }

    /**
     * Bảng kiểm soát chi phí: ngân sách – cam kết – thực tế theo từng nhóm,
     * gồm cả nhóm phát sinh chi phí mà chưa có dòng ngân sách.
     *
     * @return list<array{category: BudgetCategory, planned: string, committed: string, actual: string, variance: string, status: CostStatus}>
     */
    public function costBreakdown(Project $project): array
    {
        $costs = $this->costs->byCategory($project);
        $planned = $project->budgetItems()->get()->keyBy(fn (BudgetItem $item): string => $item->category->value);

        $rows = [];

        foreach (BudgetCategory::cases() as $category) {
            $item = $planned->get($category->value);
            $cost = $costs[$category->value] ?? null;

            if ($item === null && $cost === null) {
                continue;
            }

            $plannedAmount = Decimal::round($item?->planned_amount);
            $cost ??= ProjectCostCollector::EMPTY;

            $rows[] = [
                'category' => $category,
                'planned' => $plannedAmount,
                'committed' => $cost['committed'],
                'actual' => $cost['actual'],
                'variance' => Decimal::sub($plannedAmount, $cost['actual']),
                'status' => CostStatus::for($plannedAmount, $cost['actual']),
            ];
        }

        return $rows;
    }
}
