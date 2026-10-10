<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\SystemRole;
use App\Filament\Pages\TaskCosts;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TaskCostItem;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use App\Support\Decimal;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Danh sách MỌI chi phí của một công việc: công của nhân sự, thuê thiết bị /
 * nhà cung cấp (mua hàng), đi lại và các đề nghị chi khác — ở tab "Chi phí"
 * của công việc và trang "Chi phí theo công việc".
 */
class TaskCostsTest extends TestCase
{
    use BuildsExpenses;
    use BuildsJobs;
    use RefreshDatabase;

    private User $pm;

    private User $quay;

    private Task $task;

    private Task $child;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->quay = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Ngô Văn Quay', 'session_rate' => 600000]);
        $this->supplier = Supplier::factory()->create(['name' => 'Thiết bị Ánh Dương']);

        // Công việc có một người làm một buổi sáng, và một công việc con.
        $this->task = Task::factory()->create([
            'name' => 'Livestream hội thảo',
            'assignee_id' => $this->quay->getKey(),
            'start_date' => '2026-10-06',
            'is_all_day' => false,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'budget' => 10000000,
        ]);
        $this->child = Task::factory()->create(['name' => 'Dựng clip tóm tắt', 'parent_id' => $this->task->getKey()]);
    }

    public function test_every_kind_of_cost_of_a_task_is_on_one_list_and_adds_up(): void
    {
        $this->seedCosts();

        $items = TaskCostItem::query()->forTask($this->task)->get();
        $task = $this->task->refresh();

        // Danh sách khớp từng đồng với "Đã cam kết" và "Chi phí thực tế" của công việc.
        $this->assertSame($task->committed_cost, Decimal::round($items->sum('amount')));
        $this->assertSame($task->actual_cost, Decimal::round($items->where('is_actual', true)->sum('amount')));
        $this->assertSame('5900000.00', $task->committed_cost);
        $this->assertSame('3200000.00', $task->actual_cost);

        // Đủ các loại: công nhân sự, đi lại (đề nghị chi), thuê thiết bị (mua hàng), và phần mới cam kết.
        $summary = $items->map(fn (TaskCostItem $item): string => implode(' | ', [
            $item->sourceLabel(), $item->category->getLabel(), (int) $item->amount, $item->stageLabel(),
        ]))->sort()->values()->all();

        $this->assertSame([
            'Công nhân sự | Nhân sự | 600000 | Thực tế',
            'Yêu cầu mua hàng | Nhà cung cấp | 2000000 | Đã cam kết',
            'Đơn mua hàng | Thiết bị | 1800000 | Thực tế',
            'Đề nghị chi | Sản xuất | 300000 | Thực tế',
            'Đề nghị chi | Thiết bị | 700000 | Đã cam kết',
            'Đề nghị chi | Đi lại | 500000 | Thực tế',
        ], $summary);

        $labor = $items->firstWhere('source', 'timesheet');

        $this->assertSame('Ngô Văn Quay', $labor->partyName());
        $this->assertSame('1 buổi × 600.000 ₫', $labor->partyDetail());
        $this->assertSame('Thiết bị Ánh Dương', $items->firstWhere('source', 'purchase_order')->partyName());
        // Khoản của công việc con cũng nằm trong danh sách của công việc cha.
        $this->assertSame($this->child->getKey(), $items->firstWhere('amount', '300000.00')->task_id);
    }

    public function test_task_page_has_a_costs_tab_for_people_who_may_see_costs(): void
    {
        $this->seedCosts();

        $this->actingAs($this->pm);

        $this->get(TaskResource::getUrl('view', ['record' => $this->task]))->assertOk()->assertSee('Đề nghị chi');

        $tab = fn () => Livewire::test(CostsRelationManager::class, ['ownerRecord' => $this->task, 'pageClass' => ViewTask::class]);
        $item = fn (string $source, int $amount): TaskCostItem => TaskCostItem::query()->forTask($this->task)->where('source', $source)->where('amount', $amount)->sole();

        $tab()
            ->assertSuccessful()
            ->assertSee('Tất cả chi phí của công việc')
            ->assertCountTableRecords(6)
            // Gom theo nhóm chi phí, có tổng.
            ->assertSee('Nhân sự')
            ->assertSee('Đi lại')
            ->assertSee('Thiết bị Ánh Dương')
            ->assertSee('Ngô Văn Quay')
            ->assertSee('1 buổi × 600.000 ₫')
            ->assertSee('Dựng clip tóm tắt')
            ->assertSee('5.900.000 ₫')
            ->assertSee('3.200.000 ₫')
            // Lọc theo nhóm chi phí.
            ->filterTable('category', [BudgetCategory::Travel->value])
            ->assertCanSeeTableRecords([$item('expense', 500000)])
            ->assertCanNotSeeTableRecords([$item('timesheet', 600000), $item('purchase_order', 1800000)])
            ->resetTableFilters()
            // Chỉ phần mới cam kết, chưa chi.
            ->filterTable('is_actual', 0)
            ->assertCanSeeTableRecords([$item('expense', 700000), $item('purchase_request', 2000000)])
            ->assertCanNotSeeTableRecords([$item('timesheet', 600000)])
            ->resetTableFilters()
            ->filterTable('source', ['timesheet'])
            ->assertCountTableRecords(1);

        // Xuất danh sách ra file.
        $csv = base64_decode($tab()->callAction(TestAction::make('exportCsv')->table())->assertFileDownloaded()->effects['download']['content']);

        $this->assertStringContainsString('"Nhóm chi phí"', $csv);
        $this->assertStringContainsString('Thiết bị Ánh Dương', $csv);
        $this->assertStringContainsString('Ngô Văn Quay', $csv);
        $this->assertStringContainsString('1800000', $csv);

        // Công việc con chỉ có khoản của chính nó.
        Livewire::test(CostsRelationManager::class, ['ownerRecord' => $this->child, 'pageClass' => ViewTask::class])
            ->assertCountTableRecords(1)
            ->assertSee('300.000 ₫');

        // Người trong ê-kíp không xem được chi phí nội bộ: không có tab này.
        $this->actingAs($this->quay);

        $this->assertFalse(CostsRelationManager::canViewForRecord($this->task, ViewTask::class));
        $this->get(TaskResource::getUrl('view', ['record' => $this->task]))
            ->assertOk()
            ->assertDontSee('₫');
    }

    public function test_costs_by_task_page_lists_everything_and_respects_who_may_see_what(): void
    {
        $this->seedCosts();

        $other = Task::factory()->create(['name' => 'Chụp ảnh sản phẩm']);
        $otherItem = $this->payExpense($this->makeExpense($other, attributes: ['amount' => 900000, 'category' => BudgetCategory::Travel]));
        // Chi phí chung: không gắn công việc nào.
        $overhead = $this->payExpense(Expense::factory()->create(['employee_id' => $this->quay->getKey(), 'amount' => 400000, 'description' => 'Tiền điện văn phòng'])->refresh());

        $find = fn (int $id): TaskCostItem => TaskCostItem::query()->findOrFail('expense-'.$id);

        // Quản lý công việc (xem mọi công việc + chi phí nội bộ): thấy tất cả, kể cả chi phí chung.
        $this->actingAs($this->pm);

        $this->get(TaskCosts::getUrl())->assertOk()->assertSee('Chi phí theo công việc');

        Livewire::test(TaskCosts::class)
            ->assertCountTableRecords(8)
            ->assertCanSeeTableRecords([$find($otherItem->getKey()), $find($overhead->getKey())])
            ->assertSee('Livestream hội thảo')
            ->assertSee('Chi phí chung')
            ->filterTable('task_id', [$other->getKey()])
            ->assertCountTableRecords(1)
            ->resetTableFilters()
            ->filterTable('without_task')
            ->assertCanSeeTableRecords([$find($overhead->getKey())])
            ->assertCountTableRecords(1);

        Livewire::test(TaskCosts::class)->callAction('exportXlsx')->assertFileDownloaded();

        // Từ màn hình Chi phí có lối sang trang này.
        Livewire::test(ListExpenses::class)->assertActionVisible('taskCosts');

        // Người xem được chi phí nhưng không xem mọi công việc: chỉ chi phí của việc mình được giao, không có chi phí chung.
        $lead = $this->createUserWithPermissions(['tasks.view', 'internal_costs.view', 'expenses.view']);
        $other->update(['assignee_id' => $lead->getKey()]);

        $this->actingAs($lead);

        Livewire::test(TaskCosts::class)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$find($otherItem->getKey())])
            ->assertCanNotSeeTableRecords([$find($overhead->getKey())]);

        // Nhân viên không có quyền chi phí nội bộ: không vào được, không có nút.
        $this->actingAs($this->quay);

        $this->get(TaskCosts::getUrl())->assertForbidden();
        Livewire::test(ListExpenses::class)->assertActionHidden('taskCosts');
    }

    public function test_costs_by_task_is_in_the_menu_only_for_people_who_may_see_costs(): void
    {
        $this->actingAs($this->quay)->get('/admin')->assertOk()->assertDontSee('Chi phí theo công việc');
    }

    public function test_costs_by_task_is_in_the_menu_of_the_task_manager(): void
    {
        $this->actingAs($this->pm)->get('/admin')->assertOk()->assertSee('Chi phí theo công việc');
    }

    /**
     * Công nhân sự 600.000 (tự tính), và:
     *   - đi lại 500.000 đã thanh toán; thuê thiết bị 700.000 mới duyệt; nháp 9.000.000 (không tính);
     *   - yêu cầu mua hàng 2.000.000 đã duyệt; đơn mua hàng 1.800.000 đã nhận;
     *   - việc con: 300.000 đã thanh toán.
     * Cam kết 5.900.000, thực tế 3.200.000.
     */
    private function seedCosts(): void
    {
        $this->payExpense($this->makeExpense($this->task, attributes: ['amount' => 500000, 'category' => BudgetCategory::Travel, 'description' => 'Taxi chở thiết bị']));
        $this->approveExpense($this->makeExpense($this->task, attributes: ['amount' => 700000, 'category' => BudgetCategory::Equipment, 'vendor_id' => $this->supplier->getKey(), 'description' => 'Thuê micro không dây']));
        $this->makeExpense($this->task, attributes: ['amount' => 9000000]);
        $this->payExpense($this->makeExpense($this->child, attributes: ['amount' => 300000, 'category' => BudgetCategory::Production, 'description' => 'Nhạc nền bản quyền']));

        $procurement = $this->approver(SystemRole::Procurement);
        $requests = app(PurchaseRequestWorkflow::class);
        $request = PurchaseRequest::factory()->create([
            'task_id' => $this->task->getKey(),
            'requester_id' => $procurement->getKey(),
            'category' => BudgetCategory::Supplier,
            'description' => 'Thuê màn hình LED',
            'amount' => 2000000,
        ]);
        $request = $requests->approve($requests->submit($request, $procurement), $this->pm);
        $requests->approve($request, $this->approver(SystemRole::Accountant));

        $orders = app(PurchaseOrderWorkflow::class);
        $order = PurchaseOrder::factory()->create(['task_id' => $this->task->getKey(), 'supplier_id' => $this->supplier->getKey(), 'category' => BudgetCategory::Equipment]);
        $order->items()->create(['description' => 'Thuê máy quay 2 ngày', 'quantity' => 2, 'unit_price' => 900000, 'tax_rate' => 10]);
        $admin = $this->approver(SystemRole::SuperAdmin);
        $orders->receive($orders->order($orders->recalculate($order), $admin), $admin);

        auth()->logout();
    }
}
