<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemRole;
use App\Filament\Pages\Reports\CostReport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Timesheets\Pages\ManageTimesheets;
use App\Filament\Resources\Timesheets\TimesheetResource;
use App\Models\CostLine;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use App\Services\Reports\FinancialReport;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Đề nghị chi, mua hàng và chấm công KHÔNG bắt buộc gắn công việc. Chứng từ
 * không gắn công việc là chi phí chung của công ty — vẫn nằm trong chi phí /
 * lợi nhuận theo tháng, nhưng không tính vào công việc hay hợp đồng nào.
 */
class OverheadCostTest extends TestCase
{
    use BuildsExpenses;
    use BuildsJobs;
    use RefreshDatabase;

    private User $employee;

    private User $pm;

    private User $procurement;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));

        $this->employee = $this->createUserWithRole(SystemRole::Employee, ['session_rate' => 100000]);
        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->procurement = $this->approver(SystemRole::Procurement);
        $this->accountant = $this->approver(SystemRole::Accountant);
    }

    public function test_an_expense_without_a_task_is_company_overhead(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->employee);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'task_id' => null,
                'category' => BudgetCategory::Other->value,
                'description' => 'Tiền điện văn phòng tháng 10',
                'expense_date' => '2026-10-10',
                'amount' => 3000000,
                'tax' => 300000,
                'payment_method' => PaymentMethod::BankTransfer->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::query()->sole();

        $this->assertNull($expense->task_id);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);

        // Vẫn đi đủ quy trình duyệt và thanh toán như mọi khoản chi khác.
        $expense = $this->payExpense($expense);

        $this->assertSame(ExpenseStatus::Paid, $expense->status);

        // Tính vào chi phí của tháng, nhưng không vào công việc hay hợp đồng nào.
        $line = CostLine::query()->findOrFail('expense-'.$expense->getKey());

        $this->assertNull($line->task_id);
        $this->assertSame('3000000.00', $line->amount);
        $this->assertSame('3000000.00', app(FinancialReport::class)->month()['cost']);
        $this->assertSame('0.00', $job->refresh()->actual_cost);
        $this->assertSame('0.00', $job->contract->refresh()->actual_cost);

        // Người đề nghị thấy khoản chi của mình; trang xem không lỗi khi không có công việc.
        $this->assertSame(1, Expense::query()->visibleTo($this->employee)->count());
        $this->get(ExpenseResource::getUrl('view', ['record' => $expense]))->assertOk()->assertSee('Không gắn công việc');

        $withTask = $this->makeExpense($job);

        $this->actingAs($this->accountant);

        Livewire::test(ListExpenses::class)
            ->assertCanSeeTableRecords([$expense, $withTask])
            ->filterTable('without_task')
            ->assertCanSeeTableRecords([$expense])
            ->assertCanNotSeeTableRecords([$withTask]);
    }

    public function test_purchasing_works_without_a_task(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $supplier = Supplier::factory()->create();

        $this->actingAs($this->procurement);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm([
                'task_id' => null,
                'category' => BudgetCategory::Equipment->value,
                'description' => 'Thẻ nhớ dùng chung',
                'amount' => 8000000,
                'supplier_id' => $supplier->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = PurchaseRequest::query()->sole();

        $this->assertNull($request->task_id);

        $requests = app(PurchaseRequestWorkflow::class);
        $request = $requests->submit($request, $this->procurement);
        $request = $requests->approve($request, $this->pm);
        $request = $requests->approve($request, $this->accountant);

        $this->assertSame(PurchaseRequestStatus::Approved, $request->status);

        // Đơn mua hàng lập từ yêu cầu cũng không gắn công việc.
        Livewire::withQueryParams(['request' => $request->getKey()])
            ->test(CreatePurchaseOrder::class)
            ->assertSchemaStateSet(['purchase_request_id' => $request->getKey(), 'task_id' => null])
            ->fillForm([
                'items' => [
                    ['description' => 'Thẻ nhớ 256GB', 'quantity' => 4, 'unit_price' => 1500000, 'tax_rate' => 10],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $order = PurchaseOrder::query()->sole();
        $orders = app(PurchaseOrderWorkflow::class);

        $this->assertNull($order->task_id);

        $order = $orders->receive($orders->order($order, $this->procurement), $this->procurement);

        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(PurchaseRequestStatus::Received, $request->refresh()->status);

        // Hàng đã nhận là chi phí chung của tháng: 4 × 1.500.000 trước thuế.
        $this->assertNull(CostLine::query()->findOrFail('purchase_order-'.$order->getKey())->task_id);
        $this->assertSame('6000000.00', app(FinancialReport::class)->month()['cost']);

        $this->get(PurchaseRequestResource::getUrl('index'))->assertOk()->assertSee($request->code);
        $this->get(PurchaseRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('Không gắn công việc');
        $this->get(PurchaseOrderResource::getUrl('index'))->assertOk()->assertSee($order->code);
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee('Không gắn công việc');
    }

    public function test_time_is_logged_with_or_without_a_task(): void
    {
        $task = Task::factory()->create(['assignee_id' => $this->employee->getKey(), 'name' => 'Sắp xếp kho thiết bị']);

        $this->actingAs($this->employee);

        Livewire::test(ManageTimesheets::class)
            ->callAction('create', data: [
                'task_id' => null,
                'work_date' => '2026-10-14',
                'sessions' => 3,
                'description' => 'Họp công ty',
            ])
            ->assertHasNoFormErrors();

        $timesheet = Timesheet::query()->sole();

        $this->assertNull($timesheet->task_id);
        $this->assertSame('300000.00', $timesheet->cost);
        $this->assertSame('300000.00', app(FinancialReport::class)->month()['cost']);

        $this->assertTrue($this->employee->can('update', $timesheet));
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertSee('Chi phí chung');

        $onTask = Timesheet::factory()->create(['task_id' => $task->getKey(), 'user_id' => $this->employee->getKey(), 'sessions' => 2]);

        $this->assertSame('2.00', $task->refresh()->actual_sessions);
        $this->assertSame('200000.00', $task->actual_cost);

        Livewire::test(ManageTimesheets::class)
            ->filterTable('without_task')
            ->assertCanSeeTableRecords([$timesheet])
            ->assertCanNotSeeTableRecords([$onTask]);
    }

    public function test_cost_report_groups_costs_without_a_task_as_overhead(): void
    {
        $job = $this->makeJob(['name' => 'Quay TVC mùa thu']);

        $overhead = $this->payExpense(Expense::factory()->create([
            'employee_id' => $this->employee->getKey(),
            'amount' => 2000000,
        ])->refresh());
        $onJob = $this->payExpense($this->makeExpense($job, attributes: ['amount' => 5000000]));

        $overheadLine = CostLine::query()->findOrFail('expense-'.$overhead->getKey());
        $jobLine = CostLine::query()->findOrFail('expense-'.$onJob->getKey());

        // Chỉ khoản có gắn công việc mới vào chi phí thực tế của công việc.
        $this->assertSame('5000000.00', $job->refresh()->actual_cost);
        $this->assertSame('7000000.00', app(FinancialReport::class)->month()['cost']);

        $this->actingAs($this->accountant);

        Livewire::test(CostReport::class)
            ->assertCanSeeTableRecords([$overheadLine, $jobLine])
            ->assertSee('Chi phí chung')
            ->assertSee('Quay TVC mùa thu')
            // Kế toán xem được hợp đồng nên thấy cột Hợp đồng.
            ->assertSee($job->contract->code)
            ->assertSee('7.000.000 ₫')
            ->filterTable('contract', $job->contract_id)
            ->assertCanSeeTableRecords([$jobLine])
            ->assertCanNotSeeTableRecords([$overheadLine])
            ->resetTableFilters()
            ->filterTable('without_task')
            ->assertCanSeeTableRecords([$overheadLine])
            ->assertCanNotSeeTableRecords([$jobLine]);
    }
}
