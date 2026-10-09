<?php

namespace Tests\Concerns;

use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\Expenses\ExpenseWorkflow;

/**
 * Dựng nhanh đề nghị chi và đưa nó qua quy trình duyệt mặc định trong các bài test.
 * Lớp test dùng trait này cần gọi seedAccessControl() trước.
 */
trait BuildsExpenses
{
    /** @var array<string, User> */
    private array $approvers = [];

    /**
     * Đề nghị chi nháp (mặc định 1.000.000, không thuế, nhóm "Khác").
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeExpense(?Project $project = null, ?User $employee = null, array $attributes = []): Expense
    {
        return Expense::factory()->create([
            'project_id' => ($project ?? Project::factory()->create())->getKey(),
            'employee_id' => ($employee ?? $this->approver(SystemRole::Employee))->getKey(),
            ...$attributes,
        ])->refresh();
    }

    /**
     * Gửi duyệt rồi duyệt hết các cấp theo quy trình mặc định → "Đã duyệt".
     */
    protected function approveExpense(Expense $expense): Expense
    {
        $workflow = $this->expenseWorkflow();
        $expense = $workflow->submit($expense, $expense->employee);

        while ($expense->status->isInApproval()) {
            $role = SystemRole::from($expense->currentApproval()->first()->current_role);

            $expense = $workflow->approve($expense, $this->approver($role));
        }

        return $expense;
    }

    /**
     * Duyệt xong và thanh toán → "Đã thanh toán" (tính vào chi phí thực tế).
     */
    protected function payExpense(Expense $expense): Expense
    {
        return $this->expenseWorkflow()->pay(
            $this->approveExpense($expense),
            $this->approver(SystemRole::Accountant),
            PaymentMethod::BankTransfer,
        );
    }

    /**
     * Một người dùng cho mỗi vai trò, tạo khi cần và dùng lại trong cùng bài test.
     */
    protected function approver(SystemRole $role): User
    {
        return $this->approvers[$role->value] ??= $this->createUserWithRole($role);
    }

    protected function expenseWorkflow(): ExpenseWorkflow
    {
        return app(ExpenseWorkflow::class);
    }
}
