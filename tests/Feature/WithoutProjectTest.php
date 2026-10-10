<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\Reports\CostReport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\TaskResource;
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
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

/**
 * Công việc, đề nghị chi, mua hàng và chấm công KHÔNG bắt buộc gắn dự án:
 * phần lớn công việc là việc độc lập. Chi phí không gắn dự án là chi phí chung
 * của công ty — vẫn nằm trong chi phí / lợi nhuận theo tháng, nhưng không tính
 * vào lợi nhuận của dự án nào.
 */
class WithoutProjectTest extends TestCase
{
    use BuildsExpenses;
    use BuildsProjects;
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

        $this->employee = $this->createUserWithRole(SystemRole::Employee, ['hourly_rate' => 100000]);
        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->procurement = $this->approver(SystemRole::Procurement);
        $this->accountant = $this->approver(SystemRole::Accountant);
    }

    public function test_a_standalone_task_is_created_and_worked_on_without_a_project(): void
    {
        $project = $this->makeProject();
        $projectTask = Task::factory()->create(['project_id' => $project->getKey(), 'progress' => 40]);

        $this->actingAs($this->pm);

        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'project_id' => null,
                'name' => 'Bảo dưỡng máy quay',
                'assignee_id' => $this->employee->getKey(),
                'due_date' => today()->addWeek()->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $task = Task::query()->where('name', 'Bảo dưỡng máy quay')->sole();

        $this->assertNull($task->project_id);
        $this->assertSame(1, $task->sort_order);

        // Việc độc lập không làm đổi tiến độ của dự án nào.
        $task->update(['status' => TaskStatus::Done]);

        $this->assertSame(100, $task->refresh()->progress);
        $this->assertSame(40, $project->refresh()->progress);

        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task, $projectTask])
            ->assertSee('Việc độc lập')
            ->filterTable('without_project')
            ->assertCanSeeTableRecords([$task])
            ->assertCanNotSeeTableRecords([$projectTask]);

        // Người được giao vẫn thấy và cập nhật được việc của mình.
        $this->actingAs($this->employee);

        $this->get(TaskResource::getUrl('index'))->assertOk();
        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task])
            ->assertCanNotSeeTableRecords([$projectTask]);
        $this->assertTrue($this->employee->can('update', $task));
    }

    public function test_an_expense_without_a_project_is_company_overhead(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->employee);

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'project_id' => null,
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

        $this->assertNull($expense->project_id);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);

        // Vẫn đi đủ quy trình duyệt và thanh toán như mọi khoản chi khác.
        $expense = $this->payExpense($expense);

        $this->assertSame(ExpenseStatus::Paid, $expense->status);

        // Tính vào chi phí của tháng, nhưng không vào dự án nào.
        $line = CostLine::query()->findOrFail('expense-'.$expense->getKey());

        $this->assertNull($line->project_id);
        $this->assertSame('3000000.00', $line->amount);
        $this->assertSame('3000000.00', app(FinancialReport::class)->month()['cost']);
        $this->assertSame('0.00', $project->refresh()->actual_cost);

        // Người đề nghị thấy khoản chi của mình; trang xem không lỗi khi thiếu dự án.
        $this->assertSame(1, Expense::query()->visibleTo($this->employee)->count());
        $this->get(ExpenseResource::getUrl('view', ['record' => $expense]))->assertOk()->assertSee('Không gắn dự án');

        $withProject = $this->makeExpense($project);

        $this->actingAs($this->accountant);

        Livewire::test(ListExpenses::class)
            ->assertCanSeeTableRecords([$expense, $withProject])
            ->filterTable('without_project')
            ->assertCanSeeTableRecords([$expense])
            ->assertCanNotSeeTableRecords([$withProject]);
    }

    public function test_purchasing_works_without_a_project(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $supplier = Supplier::factory()->create();

        $this->actingAs($this->procurement);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm([
                'project_id' => null,
                'category' => BudgetCategory::Equipment->value,
                'description' => 'Thẻ nhớ dùng chung',
                'amount' => 8000000,
                'supplier_id' => $supplier->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = PurchaseRequest::query()->sole();

        $this->assertNull($request->project_id);

        $requests = app(PurchaseRequestWorkflow::class);
        $request = $requests->submit($request, $this->procurement);
        $request = $requests->approve($request, $this->pm);
        $request = $requests->approve($request, $this->accountant);

        $this->assertSame(PurchaseRequestStatus::Approved, $request->status);

        // Đơn mua hàng lập từ yêu cầu cũng không gắn dự án.
        Livewire::withQueryParams(['request' => $request->getKey()])
            ->test(CreatePurchaseOrder::class)
            ->assertSchemaStateSet(['purchase_request_id' => $request->getKey(), 'project_id' => null])
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

        $this->assertNull($order->project_id);

        $order = $orders->receive($orders->order($order, $this->procurement), $this->procurement);

        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(PurchaseRequestStatus::Received, $request->refresh()->status);

        // Hàng đã nhận là chi phí chung của tháng: 4 × 1.500.000 trước thuế.
        $this->assertNull(CostLine::query()->findOrFail('purchase_order-'.$order->getKey())->project_id);
        $this->assertSame('6000000.00', app(FinancialReport::class)->month()['cost']);

        $this->get(PurchaseRequestResource::getUrl('index'))->assertOk()->assertSee($request->code);
        $this->get(PurchaseRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('Không gắn dự án');
        $this->get(PurchaseOrderResource::getUrl('index'))->assertOk()->assertSee($order->code);
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee('Không gắn dự án');
    }

    public function test_time_is_logged_without_a_project_and_only_against_standalone_tasks(): void
    {
        $project = $this->makeProject();
        $standalone = Task::factory()->create(['project_id' => null, 'assignee_id' => $this->employee->getKey(), 'name' => 'Sắp xếp kho thiết bị']);

        $this->actingAs($this->employee);

        Livewire::test(ManageTimesheets::class)
            ->callAction('create', data: [
                'project_id' => null,
                'task_id' => $standalone->getKey(),
                'work_date' => '2026-10-14',
                'hours' => 3,
                'description' => 'Dọn kho',
            ])
            ->assertHasNoFormErrors();

        $timesheet = Timesheet::query()->sole();

        $this->assertNull($timesheet->project_id);
        $this->assertSame('300000.00', $timesheet->cost);
        $this->assertSame('3.00', $standalone->refresh()->actual_hours);
        $this->assertSame('0.00', $project->refresh()->labor_cost);
        $this->assertSame('300000.00', app(FinancialReport::class)->month()['cost']);

        $this->assertTrue($this->employee->can('update', $timesheet));
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertSee('Không gắn dự án');

        $inProject = Timesheet::factory()->create(['project_id' => $project->getKey(), 'user_id' => $this->employee->getKey()]);

        Livewire::test(ManageTimesheets::class)
            ->filterTable('without_project')
            ->assertCanSeeTableRecords([$timesheet])
            ->assertCanNotSeeTableRecords([$inProject]);
    }

    public function test_cost_report_groups_non_project_costs_as_overhead(): void
    {
        $project = $this->makeProject();

        $overhead = $this->payExpense(Expense::factory()->create([
            'project_id' => null,
            'employee_id' => $this->employee->getKey(),
            'amount' => 2000000,
        ])->refresh());
        $inProject = $this->payExpense($this->makeExpense($project, attributes: ['amount' => 5000000]));

        $overheadLine = CostLine::query()->findOrFail('expense-'.$overhead->getKey());
        $projectLine = CostLine::query()->findOrFail('expense-'.$inProject->getKey());

        // Chỉ khoản có gắn dự án mới vào chi phí thực tế của dự án.
        $this->assertSame('5000000.00', $project->refresh()->actual_cost);
        $this->assertSame('7000000.00', app(FinancialReport::class)->month()['cost']);

        $this->actingAs($this->accountant);

        Livewire::test(CostReport::class)
            ->assertCanSeeTableRecords([$overheadLine, $projectLine])
            ->assertSee('Chi phí chung')
            ->assertSee('7.000.000 ₫')
            ->filterTable('without_project')
            ->assertCanSeeTableRecords([$overheadLine])
            ->assertCanNotSeeTableRecords([$projectLine]);
    }
}
