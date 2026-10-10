<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Exceptions\WorkflowException;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\Timesheet;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use App\Services\Tasks\TaskCostCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Kiểm soát chi phí (mục 19, 33–34): Ngân sách – Cam kết – Thực tế của công
 * việc, cảnh báo ngân sách, và chi phí / lợi nhuận của hợp đồng mà công việc
 * gắn vào.
 */
class CostControlTest extends TestCase
{
    use BuildsExpenses;
    use BuildsJobs;
    use RefreshDatabase;

    private Contract $contract;

    private Task $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        // Hợp đồng 100 triệu; công việc gắn hợp đồng có ngân sách 50 triệu.
        $this->contract = $this->makeActiveContract(['contract_value' => 100000000]);
        $this->job = $this->makeJob(['budget' => 50000000], $this->contract);
    }

    public function test_expenses_become_committed_when_approved_and_actual_when_paid(): void
    {
        $expense = $this->makeExpense($this->job, attributes: [
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

        $this->assertSame('46000000.00', $this->job->refresh()->remainingBudget());

        // Hợp đồng: chi phí là chi phí của công việc; lợi nhuận = giá trị hợp đồng − chi phí thực tế.
        $contract = $this->contract->refresh();

        $this->assertSame('50000000.00', $contract->budget);
        $this->assertSame('4000000.00', $contract->committed_cost);
        $this->assertSame('4000000.00', $contract->actual_cost);
        $this->assertSame('96000000.00', $contract->actual_profit);
        $this->assertSame('96.00', $contract->profit_margin);
    }

    public function test_purchases_and_timesheets_are_part_of_the_task_cost(): void
    {
        $pm = $this->approver(SystemRole::ProjectManager);
        $procurement = $this->approver(SystemRole::Procurement);
        $employee = $this->createUserWithRole(SystemRole::Employee, ['hourly_rate' => 150000]);

        // Yêu cầu mua hàng 6 triệu do bộ phận mua hàng lập, quản lý và kế toán duyệt → cam kết.
        $request = PurchaseRequest::factory()->create([
            'task_id' => $this->job->getKey(),
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
            'task_id' => $this->job->getKey(),
            'purchase_request_id' => $request->getKey(),
            'category' => BudgetCategory::Equipment,
        ]);
        $order->items()->create(['description' => 'Micro không dây', 'quantity' => 2, 'unit_price' => 2750000, 'tax_rate' => 10]);
        $orders = app(PurchaseOrderWorkflow::class);
        $order = $orders->order($orders->recalculate($order), $procurement);

        $this->assertCosts(committed: '5500000.00', actual: '0.00');

        // Nhận hàng → thực tế.
        $orders->receive($order, $procurement);
        $this->assertCosts(committed: '5500000.00', actual: '5500000.00');

        // 10 giờ công × 150.000 = 1,5 triệu chi phí nhân sự.
        Timesheet::factory()->create(['task_id' => $this->job->getKey(), 'user_id' => $employee->getKey(), 'hours' => 10]);

        $this->assertCosts(committed: '7000000.00', actual: '7000000.00');

        // Chi phí theo nhóm.
        $byCategory = app(TaskCostCollector::class)->byCategory($this->job);

        $this->assertSame('1500000.00', $byCategory[BudgetCategory::Personnel->value]['actual']);
        $this->assertSame('5500000.00', $byCategory[BudgetCategory::Equipment->value]['actual']);

        $contract = $this->contract->refresh();

        $this->assertSame('93000000.00', $contract->actual_profit);
        $this->assertSame('93.00', $contract->profit_margin);
    }

    /**
     * Chi phí của công việc con được tính vào công việc cha, và hợp đồng chỉ
     * cộng một lần.
     */
    public function test_costs_of_subtasks_roll_up_to_the_parent_and_the_contract(): void
    {
        $child = Task::factory()->create(['parent_id' => $this->job->getKey(), 'name' => 'Quay ngoại cảnh']);
        $grandchild = Task::factory()->create(['parent_id' => $child->getKey(), 'name' => 'Thuê xe']);

        // Việc con tự theo hợp đồng của việc cha.
        $this->assertSame($this->contract->getKey(), $child->refresh()->contract_id);
        $this->assertSame($this->contract->getKey(), $grandchild->refresh()->contract_id);

        $this->payExpense($this->makeExpense($this->job, attributes: ['amount' => 1000000]));
        $this->payExpense($this->makeExpense($child, attributes: ['amount' => 2000000]));
        $expense = $this->payExpense($this->makeExpense($grandchild, attributes: ['amount' => 4000000]));

        $this->assertSame('4000000.00', $grandchild->refresh()->actual_cost);
        $this->assertSame('6000000.00', $child->refresh()->actual_cost);
        $this->assertSame('7000000.00', $this->job->refresh()->actual_cost);
        $this->assertSame('7000000.00', $this->contract->refresh()->actual_cost);
        $this->assertSame('93000000.00', $this->contract->actual_profit);

        // Tách việc con ra khỏi việc cha: chi phí đi theo việc con, hợp đồng vẫn tính vì việc con còn gắn hợp đồng.
        $child->update(['parent_id' => null]);

        $this->assertSame('1000000.00', $this->job->refresh()->actual_cost);
        $this->assertSame('6000000.00', $child->refresh()->actual_cost);
        $this->assertSame('7000000.00', $this->contract->refresh()->actual_cost);

        // Gỡ việc đó khỏi hợp đồng: hợp đồng không còn tính phần này.
        $child->linkToContract(['contract_id' => null]);

        $this->assertNull($grandchild->refresh()->contract_id);
        $this->assertSame('1000000.00', $this->contract->refresh()->actual_cost);
        $this->assertSame('99000000.00', $this->contract->actual_profit);

        // Xoá khoản chi của việc cháu: việc cha của nó giảm theo.
        $expense->delete();

        $this->assertSame('2000000.00', $child->refresh()->actual_cost);
    }

    public function test_costs_of_tasks_without_a_contract_do_not_touch_any_contract(): void
    {
        $standalone = Task::factory()->create(['budget' => 3000000]);

        $this->payExpense($this->makeExpense($standalone, attributes: ['amount' => 2000000]));

        $this->assertSame('2000000.00', $standalone->refresh()->actual_cost);
        $this->assertSame('0.00', $this->contract->refresh()->actual_cost);
        $this->assertSame('50000000.00', $this->contract->budget);
        $this->assertSame('100.00', $this->contract->profit_margin);
    }

    /**
     * Rule 6: cảnh báo khi chi phí thực tế ≥ 80% ngân sách.
     * Rule 7: vượt ngân sách khi chi phí thực tế > ngân sách.
     */
    public function test_task_warns_at_eighty_percent_and_flags_over_budget(): void
    {
        $director = $this->approver(SystemRole::Director);
        $pm = $this->approver(SystemRole::ProjectManager);
        $employee = $this->approver(SystemRole::Employee);
        $title = 'Công việc vượt ngân sách';

        $spend = fn (int $amount) => $this->payExpense($this->makeExpense($this->job, attributes: [
            'category' => BudgetCategory::Personnel,
            'amount' => $amount,
        ]));

        // Chưa đặt ngân sách thì không cảnh báo.
        $this->assertSame(CostStatus::NoBudget, Task::factory()->create()->costStatus());

        // Ngân sách 50 triệu. 39 triệu = 78% → bình thường.
        $spend(39000000);
        $this->assertSame(CostStatus::Ok, $this->job->refresh()->costStatus());
        $this->assertSame(0, Task::query()->nearBudget()->count());

        // 40 triệu = 80% → cảnh báo.
        $spend(1000000);
        $this->assertSame(CostStatus::Warning, $this->job->refresh()->costStatus());
        $this->assertSame(1, Task::query()->nearBudget()->count());

        // Đúng bằng ngân sách vẫn là cảnh báo, chưa vượt.
        $spend(10000000);
        $this->assertSame(CostStatus::Warning, $this->job->refresh()->costStatus());
        $this->assertSame(0, Task::query()->overBudget()->count());
        $this->assertSame(0, $director->notifications()->where('data->title', $title)->count());

        // Vượt 1 đồng → vượt ngân sách, báo cho người quản lý chi phí (Giám đốc, Quản lý công việc).
        $spend(1);
        $job = $this->job->refresh();

        $this->assertSame(CostStatus::Over, $job->costStatus());
        $this->assertSame(1, Task::query()->overBudget()->count());
        $this->assertSame('-1.00', $job->remainingBudget());
        $this->assertSame(1, $director->notifications()->where('data->title', $title)->count());
        $this->assertSame(1, $pm->notifications()->where('data->title', $title)->count());
        // Ngân sách là thông tin nội bộ: nhân viên không nhận thông báo này.
        $this->assertSame(0, $employee->notifications()->where('data->title', $title)->count());

        // Đã vượt rồi thì chi thêm không báo lại.
        $spend(1000000);
        $this->assertSame(1, $director->notifications()->where('data->title', $title)->count());
    }

    public function test_moving_or_deleting_an_expense_updates_both_tasks(): void
    {
        $other = $this->makeJob();
        $expense = $this->payExpense($this->makeExpense($this->job, attributes: ['amount' => 2000000]));

        $this->assertSame('2000000.00', $this->job->refresh()->actual_cost);

        // Chuyển khoản chi sang công việc khác (sửa dữ liệu bởi quản trị).
        $expense->forceFill(['task_id' => $other->getKey()])->save();

        $this->assertSame('0.00', $this->job->refresh()->actual_cost);
        $this->assertSame('2000000.00', $other->refresh()->actual_cost);
        $this->assertSame('0.00', $this->contract->refresh()->actual_cost);
        $this->assertSame('2000000.00', $other->contract->refresh()->actual_cost);

        // Xoá mềm thì không còn tính vào chi phí; khôi phục thì tính lại.
        $expense->delete();
        $this->assertSame('0.00', $other->refresh()->actual_cost);

        $expense->restore();
        $this->assertSame('2000000.00', $other->refresh()->actual_cost);
    }

    public function test_documents_cannot_move_forward_on_a_cancelled_task(): void
    {
        $expense = $this->makeExpense($this->job);
        $this->job->update(['status' => TaskStatus::Cancelled]);

        try {
            $this->expenseWorkflow()->submit($expense, $expense->employee);
            $this->fail('Công việc đã huỷ thì không gửi duyệt đề nghị chi được.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('Công việc đã huỷ', $exception->getMessage());
        }

        // Chi phí đã phát sinh của công việc đã huỷ vẫn được tính.
        $paid = $this->payExpense($this->makeExpense($other = $this->makeJob([], $this->contract), attributes: ['amount' => 500000]));
        $other->update(['status' => TaskStatus::Cancelled]);

        $this->assertSame('500000.00', $this->contract->refresh()->actual_cost);
        $this->assertNotNull($paid->paid_at);
    }

    private function assertCosts(string $committed, string $actual): void
    {
        $job = $this->job->refresh();

        $this->assertSame($committed, $job->committed_cost, 'Chi phí cam kết');
        $this->assertSame($actual, $job->actual_cost, 'Chi phí thực tế');
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
