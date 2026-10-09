<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class ExpensePagesTest extends TestCase
{
    use BuildsExpenses;
    use BuildsProjects;
    use RefreshDatabase;

    private User $employee;

    private User $pm;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->employee = $this->approver(SystemRole::Employee);
        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->accountant = $this->approver(SystemRole::Accountant);
    }

    public function test_employee_creates_an_expense_request_from_the_form(): void
    {
        $project = $this->makeProject();
        $project->memberships()->create(['user_id' => $this->employee->getKey()]);

        $this->actingAs($this->employee);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'project_id' => $project->getKey(),
                'category' => BudgetCategory::Travel->value,
                'description' => 'Taxi đi quay ngoại cảnh',
                'expense_date' => today()->toDateString(),
                'amount' => 450000,
                'tax' => 45000,
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::query()->first();

        $this->assertNotNull($expense);
        $this->assertSame($this->employee->getKey(), $expense->employee_id);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertSame('495000.00', $expense->total_amount);
        $this->assertSame(BudgetCategory::Travel, $expense->category);
    }

    public function test_expense_form_validates_required_fields(): void
    {
        $this->actingAs($this->employee);

        Livewire::test(CreateExpense::class)
            ->fillForm(['project_id' => null, 'category' => null, 'description' => '', 'amount' => 0])
            ->call('create')
            ->assertHasFormErrors(['project_id', 'category', 'description', 'amount']);
    }

    public function test_pages_follow_ownership_and_status(): void
    {
        $mine = $this->makeExpense(attributes: ['description' => 'Khoản chi của tôi']);
        $other = $this->makeExpense(employee: $this->createUserWithRole(SystemRole::Employee), attributes: ['description' => 'Khoản chi của người khác']);

        $this->actingAs($this->employee);

        $this->get(ExpenseResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Khoản chi của tôi')
            ->assertDontSee('Khoản chi của người khác');

        $this->get(ExpenseResource::getUrl('view', ['record' => $mine]))->assertOk()->assertSee('1.000.000');
        $this->get(ExpenseResource::getUrl('edit', ['record' => $mine]))->assertOk();
        $this->get(ExpenseResource::getUrl('view', ['record' => $other]))->assertNotFound();

        // Đã gửi duyệt thì không sửa được nữa, kể cả Super Admin.
        $mine = $this->expenseWorkflow()->submit($mine, $this->employee);

        $this->get(ExpenseResource::getUrl('edit', ['record' => $mine]))->assertForbidden();

        $this->actingAs($this->approver(SystemRole::SuperAdmin));

        $this->assertFalse(ExpenseResource::canEdit($mine));
        $this->assertFalse(ExpenseResource::canDelete($mine));

        // Sales không có quyền xem chi phí.
        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(ExpenseResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_workflow_buttons_follow_status_and_role(): void
    {
        $expense = $this->makeExpense(attributes: ['amount' => 3000000]);

        // Người đề nghị: gửi duyệt.
        $this->actingAs($this->employee);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertActionVisible('submit')
            ->assertActionHidden('approve')
            ->assertActionHidden('pay')
            ->callAction('submit');

        $this->assertSame(ExpenseStatus::Submitted, $expense->refresh()->status);

        // Kế toán chưa tới lượt: không thấy nút duyệt.
        $this->actingAs($this->accountant);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('pay');

        // PM duyệt bước của mình.
        $this->actingAs($this->pm);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertSee('Quy trình duyệt')
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->callAction('approve', data: ['comment' => 'Đúng kế hoạch.']);

        $this->assertSame(ExpenseStatus::AccountingReview, $expense->refresh()->status);

        // Kế toán duyệt rồi thanh toán (Rule 2: nút thanh toán chỉ có sau khi đã duyệt).
        $this->actingAs($this->accountant);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertActionVisible('approve')
            ->assertActionHidden('pay')
            ->callAction('approve');

        $this->assertSame(ExpenseStatus::Approved, $expense->refresh()->status);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertActionHidden('approve')
            ->assertActionVisible('pay')
            ->callAction('pay', data: [
                'payment_method' => PaymentMethod::BankTransfer->value,
                'paid_at' => today()->toDateString(),
                'reference' => 'UNC-777',
            ])
            ->assertHasNoFormErrors();

        $expense->refresh();

        $this->assertSame(ExpenseStatus::Paid, $expense->status);
        $this->assertSame('UNC-777', $expense->payment_reference);
        $this->assertSame($this->accountant->getKey(), $expense->paid_by);
    }

    public function test_rejecting_from_the_page_requires_a_reason(): void
    {
        $expense = $this->expenseWorkflow()->submit($this->makeExpense(), $this->employee);

        $this->actingAs($this->pm);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasFormErrors(['reason' => ['required']]);

        $this->assertSame(ExpenseStatus::Submitted, $expense->refresh()->status);
    }

    public function test_approvers_see_what_is_waiting_for_them(): void
    {
        $waitingForPm = $this->expenseWorkflow()->submit($this->makeExpense(), $this->employee);
        $waitingForAccountant = $this->expenseWorkflow()->approve(
            $this->expenseWorkflow()->submit($this->makeExpense(), $this->employee),
            $this->pm,
        );
        $this->makeExpense(); // nháp

        $this->actingAs($this->pm);
        $this->assertSame('1', ExpenseResource::getNavigationBadge());

        Livewire::test(ListExpenses::class)
            ->set('activeTab', 'awaiting_me')
            ->assertCanSeeTableRecords([$waitingForPm])
            ->assertCanNotSeeTableRecords([$waitingForAccountant]);

        $this->actingAs($this->accountant);
        $this->assertSame('1', ExpenseResource::getNavigationBadge());

        // Người đề nghị không có gì phải duyệt.
        $this->actingAs($this->employee);
        $this->assertNull(ExpenseResource::getNavigationBadge());
    }
}
