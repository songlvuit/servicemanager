<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Projects\ProjectCostCollector;
use App\Services\Projects\ProjectFinancials;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

/**
 * Kiểm soát chi phí (mục 19, 33–34): Ngân sách – Cam kết – Thực tế, lợi nhuận
 * và cảnh báo ngân sách của dự án.
 */
class CostControlTest extends TestCase
{
    use BuildsExpenses;
    use BuildsProjects;
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        // Doanh thu 100 triệu; ngân sách: thiết bị 20 triệu, nhân sự 30 triệu.
        $this->project = $this->makeProject(['contract_value' => 100000000]);
        $this->project->budgetItems()->create(['category' => BudgetCategory::Equipment, 'planned_amount' => 20000000]);
        $this->project->budgetItems()->create(['category' => BudgetCategory::Personnel, 'planned_amount' => 30000000]);
    }

    public function test_expenses_become_committed_when_approved_and_actual_when_paid(): void
    {
        $expense = $this->makeExpense($this->project, attributes: [
            'category' => BudgetCategory::Equipment,
            'amount' => 4000000,
            'tax' => 400000,
        ]);

        // Nháp và đang duyệt: chưa tính vào chi phí.
        $expense = $this->expenseWorkflow()->submit($expense, $expense->employee);
        $this->assertCosts(committed: '0.00', actual: '0.00');

        // Đã duyệt: cam kết (số trước thuế).
        $expense = $this->approveRemaining($expense);
        $this->assertCosts(committed: '4000000.00', actual: '0.00');

        // Đã thanh toán: thực tế; cam kết là số luỹ kế nên vẫn giữ.
        $this->payExpenseNow($expense);
        $this->assertCosts(committed: '4000000.00', actual: '4000000.00');

        $project = $this->project->refresh();

        $this->assertSame('50000000.00', $project->budget);
        $this->assertSame('50000000.00', $project->expected_profit);
        $this->assertSame('96000000.00', $project->actual_profit);
        $this->assertSame('96.00', $project->profit_margin);
        $this->assertSame('46000000.00', $project->remainingBudget());

        $item = $project->budgetItems()->where('category', BudgetCategory::Equipment->value)->first();

        $this->assertSame('4000000.00', $item->committed_amount);
        $this->assertSame('4000000.00', $item->actual_amount);
        // Chênh lệch = kế hoạch − thực tế.
        $this->assertSame('16000000.00', $item->variance);
    }

    public function test_purchases_and_timesheets_are_part_of_the_project_cost(): void
    {
        $pm = $this->approver(SystemRole::ProjectManager);
        $procurement = $this->approver(SystemRole::Procurement);
        $employee = $this->createUserWithRole(SystemRole::Employee, ['hourly_rate' => 150000]);

        // Yêu cầu mua hàng 6 triệu do bộ phận mua hàng lập, PM và kế toán duyệt → cam kết.
        $request = PurchaseRequest::factory()->create([
            'project_id' => $this->project->getKey(),
            'requester_id' => $procurement->getKey(),
            'amount' => 6000000,
        ]);
        $requests = app(PurchaseRequestWorkflow::class);
        $request = $requests->approve($requests->submit($request, $procurement), $pm);
        $this->assertCosts(committed: '0.00', actual: '0.00');
        $request = $requests->approve($request, $this->approver(SystemRole::Accountant));

        $this->assertCosts(committed: '6000000.00', actual: '0.00');

        // Đơn đặt hàng 5,5 triệu theo yêu cầu đó: cam kết lấy theo đơn (không cộng trùng với yêu cầu).
        $order = PurchaseOrder::factory()->create([
            'project_id' => $this->project->getKey(),
            'purchase_request_id' => $request->getKey(),
        ]);
        $order->items()->create(['description' => 'Micro không dây', 'quantity' => 2, 'unit_price' => 2750000, 'tax_rate' => 10]);
        $orders = app(PurchaseOrderWorkflow::class);
        $order = $orders->order($orders->recalculate($order), $procurement);

        $this->assertCosts(committed: '5500000.00', actual: '0.00');

        // Nhận hàng → thực tế.
        $orders->receive($order, $procurement);
        $this->assertCosts(committed: '5500000.00', actual: '5500000.00');

        // 10 giờ công × 150.000 = 1,5 triệu chi phí nhân sự.
        Timesheet::factory()->create(['project_id' => $this->project->getKey(), 'user_id' => $employee->getKey(), 'hours' => 10]);

        $this->assertCosts(committed: '7000000.00', actual: '7000000.00');

        $project = $this->project->refresh();

        // Lãi lỗ dự án (mục 33).
        $this->assertSame('1500000.00', $project->labor_cost);
        $this->assertSame('5500000.00', $project->equipment_cost);
        $this->assertSame('0.00', $project->supplier_cost);
        $this->assertSame('0.00', $project->other_cost);
        $this->assertSame('93000000.00', $project->actual_profit);
        $this->assertSame('93.00', $project->profit_margin);
    }

    public function test_costs_outside_the_budget_still_count(): void
    {
        // Chi phí nhóm "Đi lại" không có dòng ngân sách.
        $this->payExpense($this->makeExpense($this->project, attributes: ['category' => BudgetCategory::Travel, 'amount' => 3000000]));

        $project = $this->project->refresh();

        $this->assertSame('3000000.00', $project->actual_cost);
        $this->assertSame('3000000.00', $project->other_cost);

        $rows = collect(app(ProjectFinancials::class)->costBreakdown($project))->keyBy(fn (array $row): string => $row['category']->value);

        $this->assertSame('0.00', $rows['travel']['planned']);
        $this->assertSame('-3000000.00', $rows['travel']['variance']);
        $this->assertSame(CostStatus::Over, $rows['travel']['status']);
        $this->assertSame(CostStatus::Ok, $rows['equipment']['status']);
    }

    /**
     * Rule 6: cảnh báo khi chi phí thực tế ≥ 80% ngân sách.
     * Rule 7: vượt ngân sách khi chi phí thực tế > ngân sách.
     */
    public function test_project_warns_at_eighty_percent_and_flags_over_budget(): void
    {
        $director = $this->approver(SystemRole::Director);
        $this->project->update(['project_manager_id' => $this->approver(SystemRole::ProjectManager)->getKey()]);

        $spend = fn (int $amount) => $this->payExpense($this->makeExpense($this->project, attributes: [
            'category' => BudgetCategory::Personnel,
            'amount' => $amount,
        ]));

        // Ngân sách 50 triệu. 39 triệu = 78% → bình thường.
        $spend(39000000);
        $this->assertSame(CostStatus::Ok, $this->project->refresh()->costStatus());

        // 40 triệu = 80% → cảnh báo.
        $spend(1000000);
        $this->assertSame(CostStatus::Warning, $this->project->refresh()->costStatus());

        // Đúng bằng ngân sách vẫn là cảnh báo, chưa vượt.
        $spend(10000000);
        $this->assertSame(CostStatus::Warning, $this->project->refresh()->costStatus());
        $this->assertSame(0, Project::query()->overBudget()->count());

        $notificationsBefore = $director->notifications()->where('data->title', 'Dự án vượt ngân sách')->count();

        // Vượt 1 đồng → vượt ngân sách, báo cho quản lý dự án và giám đốc.
        $spend(1);
        $project = $this->project->refresh();

        $this->assertSame(CostStatus::Over, $project->costStatus());
        $this->assertSame(1, Project::query()->overBudget()->count());
        $this->assertSame('-1.00', $project->remainingBudget());
        $this->assertSame($notificationsBefore + 1, $director->notifications()->where('data->title', 'Dự án vượt ngân sách')->count());
        $this->assertSame(1, $project->manager->notifications()->where('data->title', 'Dự án vượt ngân sách')->count());

        // Đã vượt rồi thì chi thêm không báo lại.
        $spend(1000000);
        $this->assertSame($notificationsBefore + 1, $director->notifications()->where('data->title', 'Dự án vượt ngân sách')->count());
    }

    public function test_moving_or_deleting_an_expense_updates_both_projects(): void
    {
        $other = $this->makeProject();
        $expense = $this->payExpense($this->makeExpense($this->project, attributes: ['amount' => 2000000]));

        $this->assertSame('2000000.00', $this->project->refresh()->actual_cost);

        // Chuyển khoản chi sang dự án khác (sửa dữ liệu bởi quản trị).
        $expense->forceFill(['project_id' => $other->getKey()])->save();

        $this->assertSame('0.00', $this->project->refresh()->actual_cost);
        $this->assertSame('2000000.00', $other->refresh()->actual_cost);

        // Xoá mềm thì không còn tính vào chi phí; khôi phục thì tính lại.
        $expense->delete();
        $this->assertSame('0.00', $other->refresh()->actual_cost);

        $expense->restore();
        $this->assertSame('2000000.00', $other->refresh()->actual_cost);
    }

    public function test_estimated_labor_cost_comes_from_task_hours(): void
    {
        $designer = User::factory()->create(['hourly_rate' => 200000]);

        $this->project->tasks()->create(['name' => 'Thiết kế', 'assignee_id' => $designer->getKey(), 'estimated_hours' => 12.5]);
        $this->project->tasks()->create(['name' => 'Chưa giao', 'estimated_hours' => 100]);

        // 12,5 giờ × 200.000; công việc chưa giao người thì chưa tính.
        $this->assertSame('2500000.00', app(ProjectCostCollector::class)->estimatedLaborCost($this->project));
    }

    private function assertCosts(string $committed, string $actual): void
    {
        $project = $this->project->refresh();

        $this->assertSame($committed, $project->committed_cost, 'Chi phí cam kết');
        $this->assertSame($actual, $project->actual_cost, 'Chi phí thực tế');
    }

    private function approveRemaining(Expense $expense): Expense
    {
        while ($expense->status->isInApproval()) {
            $role = SystemRole::from($expense->currentApproval()->first()->current_role);

            $expense = $this->expenseWorkflow()->approve($expense, $this->approver($role));
        }

        return $expense;
    }

    private function payExpenseNow(Expense $expense): Expense
    {
        return $this->expenseWorkflow()->pay($expense, $this->approver(SystemRole::Accountant), PaymentMethod::Cash);
    }
}
