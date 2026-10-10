<?php

namespace Tests\Feature;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Exceptions\WorkflowException;
use App\Models\Expense;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenses;
use Tests\TestCase;

class ExpenseWorkflowTest extends TestCase
{
    use BuildsExpenses;
    use RefreshDatabase;

    private User $employee;

    private User $pm;

    private User $accountant;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->employee = $this->approver(SystemRole::Employee);
        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->accountant = $this->approver(SystemRole::Accountant);
        $this->director = $this->approver(SystemRole::Director);
    }

    public function test_expense_gets_a_code_and_total_includes_tax(): void
    {
        $expense = $this->makeExpense(attributes: ['amount' => 2500000, 'tax' => 250000]);

        $this->assertSame('EXP-'.now()->year.'-0001', $expense->code);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertSame('2750000.00', $expense->total_amount);
    }

    public function test_small_expense_goes_through_pm_and_accounting(): void
    {
        $expense = $this->makeExpense(attributes: ['amount' => 4000000, 'tax' => 400000]);
        $workflow = $this->expenseWorkflow();

        $expense = $workflow->submit($expense, $this->employee);
        $this->assertSame(ExpenseStatus::Submitted, $expense->status);
        $this->assertNotNull($expense->submitted_at);

        $expense = $workflow->approve($expense, $this->pm);
        $this->assertSame(ExpenseStatus::AccountingReview, $expense->status);

        $expense = $workflow->approve($expense, $this->accountant, 'Chứng từ hợp lệ.');
        $this->assertSame(ExpenseStatus::Approved, $expense->status);
        $this->assertSame($this->accountant->getKey(), $expense->approved_by);
        $this->assertNotNull($expense->approved_at);
    }

    public function test_large_expense_also_needs_the_director(): void
    {
        // Tổng chi 22 triệu (> 20 triệu) → PM → Kế toán → Giám đốc.
        $expense = $this->makeExpense(attributes: ['amount' => 20000000, 'tax' => 2000000]);
        $workflow = $this->expenseWorkflow();

        $expense = $workflow->submit($expense, $this->employee);
        $expense = $workflow->approve($expense, $this->pm);
        $expense = $workflow->approve($expense, $this->accountant);

        $this->assertSame(ExpenseStatus::DirectorApproval, $expense->status);

        $expense = $workflow->approve($expense, $this->director);

        $this->assertSame(ExpenseStatus::Approved, $expense->status);
        $this->assertSame($this->director->getKey(), $expense->approved_by);
    }

    public function test_expense_requested_by_the_pm_skips_the_pm_step(): void
    {
        $expense = $this->makeExpense(employee: $this->pm);

        $expense = $this->expenseWorkflow()->submit($expense, $this->pm);

        $this->assertSame(ExpenseStatus::AccountingReview, $expense->status);
        $this->assertFalse($this->pm->can('approve', $expense));
        $this->assertTrue($this->accountant->can('approve', $expense));
    }

    public function test_only_the_approver_of_the_current_step_can_approve(): void
    {
        $expense = $this->expenseWorkflow()->submit($this->makeExpense(), $this->employee);

        $this->assertTrue($this->pm->can('approve', $expense));
        $this->assertFalse($this->accountant->can('approve', $expense));
        $this->assertFalse($this->employee->can('approve', $expense));

        $this->expectException(AuthorizationException::class);

        $this->expenseWorkflow()->approve($expense, $this->accountant);
    }

    public function test_rejection_requires_a_reason_and_can_be_revised(): void
    {
        $workflow = $this->expenseWorkflow();
        $expense = $workflow->submit($this->makeExpense(), $this->employee);

        try {
            $workflow->reject($expense, $this->pm, '  ');
            $this->fail('Từ chối phải có lý do.');
        } catch (WorkflowException) {
            $this->assertSame(ExpenseStatus::Submitted, $expense->refresh()->status);
        }

        $expense = $workflow->reject($expense, $this->pm, 'Vượt định mức.');

        $this->assertSame(ExpenseStatus::Rejected, $expense->status);
        $this->assertSame('Vượt định mức.', $expense->rejection_reason);

        // Soạn lại rồi gửi duyệt lần nữa: quy trình chạy lại từ đầu.
        $expense = $workflow->revise($expense, $this->employee);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);

        $expense = $workflow->submit($expense, $this->employee);
        $this->assertSame(ExpenseStatus::Submitted, $expense->status);
        $this->assertNull($expense->rejection_reason);
    }

    /**
     * Rule 2: không thể thanh toán đề nghị chi chưa được duyệt.
     */
    public function test_expense_cannot_be_paid_before_it_is_approved(): void
    {
        $workflow = $this->expenseWorkflow();
        $draft = $this->makeExpense();
        $inApproval = $workflow->submit($this->makeExpense(), $this->employee);
        $rejected = $workflow->reject($workflow->submit($this->makeExpense(), $this->employee), $this->pm, 'Không hợp lệ.');

        foreach ([$draft, $inApproval, $rejected] as $expense) {
            try {
                $workflow->pay($expense, $this->accountant, PaymentMethod::Cash);
                $this->fail("Không được thanh toán đề nghị chi ở trạng thái {$expense->status->value}.");
            } catch (WorkflowException $exception) {
                $this->assertStringContainsString('Chỉ thanh toán được khoản chi đã duyệt', $exception->getMessage());
                $this->assertNull($expense->refresh()->paid_at);
            }
        }
    }

    public function test_approved_expense_is_paid_then_completed(): void
    {
        $workflow = $this->expenseWorkflow();
        $expense = $this->approveExpense($this->makeExpense());

        // Người không có quyền thanh toán.
        try {
            $workflow->pay($expense, $this->pm, PaymentMethod::Cash);
            $this->fail('PM không có quyền thanh toán.');
        } catch (AuthorizationException) {
            $this->assertSame(ExpenseStatus::Approved, $expense->refresh()->status);
        }

        $expense = $workflow->pay($expense, $this->accountant, PaymentMethod::BankTransfer, now()->subDay(), 'UNC-0001');

        $this->assertSame(ExpenseStatus::Paid, $expense->status);
        $this->assertSame(PaymentMethod::BankTransfer, $expense->payment_method);
        $this->assertSame($this->accountant->getKey(), $expense->paid_by);
        $this->assertSame('UNC-0001', $expense->payment_reference);
        $this->assertTrue($expense->paid_at->isYesterday());

        $expense = $workflow->complete($expense, $this->accountant);

        $this->assertSame(ExpenseStatus::Completed, $expense->status);

        // Không thanh toán hai lần.
        $this->expectException(WorkflowException::class);
        $workflow->pay($expense, $this->accountant, PaymentMethod::Cash);
    }

    public function test_expense_cannot_be_submitted_without_amount_or_for_a_cancelled_task(): void
    {
        $workflow = $this->expenseWorkflow();

        try {
            $workflow->submit($this->makeExpense(attributes: ['amount' => 0]), $this->employee);
            $this->fail('Đề nghị chi 0 đồng không được gửi duyệt.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('lớn hơn 0', $exception->getMessage());
        }

        $task = Task::factory()->create();
        $expense = $this->makeExpense($task);
        $task->update(['status' => TaskStatus::Cancelled]);

        $this->expectException(WorkflowException::class);

        $workflow->submit($expense, $this->employee);
    }

    public function test_approvers_and_requester_are_notified(): void
    {
        $workflow = $this->expenseWorkflow();
        $expense = $workflow->submit($this->makeExpense(), $this->employee);

        $this->assertSame(1, $this->pm->notifications()->count());
        $this->assertSame(0, $this->accountant->notifications()->count());

        $expense = $workflow->approve($expense, $this->pm);
        $this->assertSame(1, $this->accountant->notifications()->count());

        $workflow->approve($expense, $this->accountant);

        $notification = $this->employee->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('Đề nghị chi đã được duyệt', $notification->data['title']);
    }

    public function test_employee_only_sees_own_expenses(): void
    {
        $mine = $this->makeExpense();
        $other = $this->makeExpense(employee: $this->createUserWithRole(SystemRole::Employee));

        // Khoản chi của người khác nhưng gắn vào công việc mình phụ trách.
        $assigned = Task::factory()->create(['assignee_id' => $this->employee->getKey()]);
        $managed = $this->makeExpense($assigned, $this->pm);

        $visible = Expense::query()->visibleTo($this->employee)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->getKey(), $managed->getKey()], $visible);
        $this->assertTrue($this->employee->can('view', $mine));
        $this->assertFalse($this->employee->can('view', $other));
        // Kế toán có quyền xem tất cả.
        $this->assertSame(3, Expense::query()->visibleTo($this->accountant)->count());
    }

    public function test_only_drafts_can_be_edited_or_deleted_by_their_owner(): void
    {
        $expense = $this->makeExpense();

        $this->assertTrue($this->employee->can('update', $expense));
        $this->assertTrue($this->employee->can('delete', $expense));
        $this->assertFalse($this->createUserWithRole(SystemRole::Employee)->can('update', $expense));

        $expense = $this->expenseWorkflow()->submit($expense, $this->employee);

        $this->assertFalse($this->employee->can('update', $expense));
        $this->assertFalse($this->employee->can('delete', $expense));
        $this->assertFalse($this->accountant->can('update', $expense));
    }
}
