<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\ScheduleStatus;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\Reports\CostReport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\CostLine;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use App\Services\Scheduling\ScheduleManager;
use App\Services\Scheduling\ScheduleTasks;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Công việc là nơi quản lý tiền của "job" — ngân sách, đề nghị chi, mua hàng,
 * giờ công — và mỗi lịch đều có công việc của nó. Các bài ở đây đi theo chiều
 * lịch → công việc (lịch nhập bằng mã qua ScheduleManager, dữ liệu cũ); chiều
 * chính trên giao diện, công việc → lịch, nằm ở TaskScheduleTest.
 */
class ScheduleTaskTest extends TestCase
{
    use BuildsExpenses;
    use BuildsJobs;
    use BuildsSchedules;
    use RefreshDatabase;

    private User $pm;

    private User $quay;

    private User $sang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        // Thứ Tư 07/10/2026.
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->pm = $this->approver(SystemRole::ProjectManager);
        $this->quay = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Quay', 'session_rate' => 150000]);
        $this->sang = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Sáng', 'session_rate' => 100000]);
    }

    public function test_a_new_schedule_creates_its_task_with_a_budget(): void
    {
        $entry = $this->schedule(['title' => 'Livestream hội thảo', 'task_budget' => 5000000], [$this->sang, $this->quay]);

        $task = Task::query()->sole();

        $this->assertSame($task->getKey(), $entry->task_id);
        $this->assertTrue($task->from_schedule);
        $this->assertSame('Livestream hội thảo', $task->name);
        // Người đầu tiên trong ê-kíp là người phụ trách.
        $this->assertSame($this->sang->getKey(), $task->assignee_id);
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame('2026-10-08', $task->start_date->toDateString());
        $this->assertSame('2026-10-08', $task->due_date->toDateString());
        $this->assertSame('5000000.00', $task->budget);

        // Lịch nghỉ không tạo công việc.
        $leave = app(ScheduleManager::class)->registerLeave($this->quay, Carbon::parse('2026-10-12')->startOfDay(), Carbon::parse('2026-10-12')->endOfDay());
        $byPlanner = $this->schedule(['title' => 'Nghỉ: Sáng', 'schedule_type_id' => ScheduleType::leave()->getKey()], [$this->sang]);

        $this->assertNull($leave->task_id);
        $this->assertNull($byPlanner->task_id);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_budget_from_the_schedule_needs_the_internal_cost_permission(): void
    {
        $planner = User::factory()->create();
        $planner->givePermissionTo(Permission::findOrCreate('schedules.create'));

        $entry = app(ScheduleManager::class)->save(null, [...$this->attributes(), 'task_budget' => 9000000], [], $planner);

        // Vẫn có công việc, nhưng ngân sách thì người này không được đặt.
        $this->assertSame('0.00', $entry->task->budget);
    }

    public function test_editing_the_schedule_updates_its_own_task(): void
    {
        $manager = app(ScheduleManager::class);
        $entry = $this->schedule(['title' => 'Quay phóng sự'], [$this->quay]);
        $task = $entry->task;

        $entry = $manager->save($entry, [
            ...$this->attributes('2026-10-09 13:00', '2026-10-10 17:00'),
            'title' => 'Quay phóng sự doanh nghiệp',
            'task_budget' => 3000000,
        ], [$this->sang->getKey()], $this->pm);

        $task->refresh();

        $this->assertSame($task->getKey(), $entry->task_id);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame('Quay phóng sự doanh nghiệp', $task->name);
        $this->assertSame('2026-10-09', $task->start_date->toDateString());
        $this->assertSame('2026-10-10', $task->due_date->toDateString());
        $this->assertSame($this->sang->getKey(), $task->assignee_id);
        $this->assertSame('3000000.00', $task->budget);

        // Trạng thái lịch quyết định trạng thái công việc ở hai đầu: xong và huỷ.
        $manager->save($entry, [...$this->attributes('2026-10-09 13:00', '2026-10-10 17:00'), 'status' => ScheduleStatus::Cancelled], [$this->sang->getKey()], $this->pm);
        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status);

        $manager->save($entry, [...$this->attributes('2026-10-09 13:00', '2026-10-10 17:00'), 'status' => ScheduleStatus::Confirmed], [$this->sang->getKey()], $this->pm);
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);

        $manager->save($entry, [...$this->attributes('2026-10-09 13:00', '2026-10-10 17:00'), 'status' => ScheduleStatus::Done], [$this->sang->getKey()], $this->pm);
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
        $this->assertSame(100, $task->progress);

        // Sửa lịch mà không gửi ngân sách thì ngân sách giữ nguyên.
        $this->assertSame('3000000.00', $task->budget);
    }

    public function test_a_schedule_can_join_an_existing_task_which_then_keeps_its_own_details(): void
    {
        $manager = app(ScheduleManager::class);
        $job = Task::factory()->create(['name' => 'TVC ra mắt sản phẩm', 'budget' => 20000000]);

        $survey = $this->schedule(['title' => 'Khảo sát bối cảnh', 'task_id' => $job->getKey()], [$this->quay]);
        $shoot = $this->schedule(['title' => 'Quay chính', 'task_id' => $job->getKey()], [$this->quay, $this->sang]);

        // Không tạo thêm công việc.
        $this->assertSame(1, Task::query()->count());
        $this->assertSame([$job->getKey(), $job->getKey()], [$survey->task_id, $shoot->task_id]);

        // Đổi tên / huỷ một lịch không đụng tới công việc tạo tay.
        $manager->save($survey, [...$this->attributes(), 'title' => 'Khảo sát lại', 'status' => ScheduleStatus::Cancelled], [$this->quay->getKey()], $this->pm);

        $job->refresh();
        $this->assertSame('TVC ra mắt sản phẩm', $job->name);
        $this->assertSame(TaskStatus::Todo, $job->status);
        $this->assertSame('20000000.00', $job->budget);

        // Xoá lịch cũng không xoá công việc tạo tay.
        $manager->delete($survey, $this->pm);
        $manager->delete($shoot, $this->pm);

        $this->assertNotNull($job->fresh());

        // Chuyển một lịch đang có công việc riêng sang công việc khác: việc cũ chưa dùng gì thì bỏ.
        $solo = $this->schedule(['title' => 'Dựng phim'], [$this->sang]);
        $autoTaskId = $solo->task_id;

        $solo = $manager->save($solo, [...$this->attributes(), 'title' => 'Dựng phim', 'task_id' => $job->getKey()], [$this->sang->getKey()], $this->pm);

        $this->assertSame($job->getKey(), $solo->task_id);
        $this->assertNull(Task::query()->find($autoTaskId));
    }

    public function test_deleting_a_schedule_removes_its_task_only_while_nothing_is_attached(): void
    {
        $manager = app(ScheduleManager::class);

        $empty = $this->schedule(['title' => 'Họp nhanh'], [$this->quay]);
        $withBudget = $this->schedule(['title' => 'Chụp ảnh', 'task_budget' => 1000000], [$this->quay]);
        $withExpense = $this->schedule(['title' => 'Livestream'], [$this->quay]);

        $this->makeExpenseFor($withExpense->task);

        foreach ([$empty, $withBudget, $withExpense] as $entry) {
            $manager->delete($entry, $this->pm);
        }

        $this->assertSame(0, ScheduleEntry::query()->count());
        $this->assertSame(['Chụp ảnh', 'Livestream'], Task::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_costs_attached_to_the_task_add_up_against_its_budget(): void
    {
        $entry = $this->schedule(['title' => 'Livestream hội thảo', 'task_budget' => 5000000], [$this->quay, $this->sang], '2026-10-06 08:00', '2026-10-06 12:00');
        $task = $entry->task;

        // Đề nghị chi: đã duyệt → cam kết; đã thanh toán → thực tế. Nháp không tính.
        $paid = $this->payExpense($this->makeExpenseFor($task, 2000000));
        $this->approveExpense($this->makeExpenseFor($task, 700000));
        $this->makeExpenseFor($task, 9000000);

        // Công của ê-kíp tự có ngay khi phân công: 08:00–12:00 là 1 buổi, 150.000 + 100.000.
        $task->refresh();
        $this->assertSame('2950000.00', $task->committed_cost);
        $this->assertSame('2250000.00', $task->actual_cost);

        // Đơn mua hàng đã nhận: 2 × 900.000 trước thuế.
        $orders = app(PurchaseOrderWorkflow::class);
        $order = PurchaseOrder::factory()->create(['task_id' => $task->getKey()]);
        $order->items()->create(['description' => 'Cáp HDMI', 'quantity' => 2, 'unit_price' => 900000, 'tax_rate' => 10]);
        $orders->receive($orders->order($orders->recalculate($order), $this->approver(SystemRole::SuperAdmin)), $this->approver(SystemRole::SuperAdmin));

        // Hai dòng công tự sinh, vào đúng công việc của lịch.
        $this->assertSame(2, Timesheet::query()->where('is_auto', true)->count());
        $this->assertSame([$task->getKey()], Timesheet::query()->distinct()->pluck('task_id')->all());

        $task->refresh();
        $this->assertSame('4750000.00', $task->committed_cost);
        $this->assertSame('4050000.00', $task->actual_cost);
        $this->assertSame('2.00', $task->actual_sessions);
        $this->assertSame('950000.00', $task->remainingBudget());
        $this->assertFalse($task->isOverBudget());

        // Việc không gắn hợp đồng: chi phí tính cho công việc, không vào hợp đồng nào; dòng chi phí ghi rõ công việc.
        $this->assertSame($task->getKey(), CostLine::query()->findOrFail('expense-'.$paid->getKey())->task_id);
        $this->assertSame('4050000.00', Decimal::round(CostLine::query()->where('task_id', $task->getKey())->sum('amount')));

        // Thêm một khoản nữa thì vượt ngân sách; xoá chứng từ thì chi phí giảm lại.
        $extra = $this->payExpense($this->makeExpenseFor($task, 1000000));
        $this->assertTrue($task->refresh()->isOverBudget());

        Timesheet::query()->orderBy('id')->first()->delete();
        $this->assertSame('4900000.00', $task->refresh()->actual_cost);

        // Chuyển chứng từ sang công việc khác: cả hai công việc được tính lại.
        $other = Task::factory()->create();
        $extra->update(['task_id' => $other->getKey()]);

        $this->assertSame('3900000.00', $task->refresh()->actual_cost);
        $this->assertSame('1000000.00', $other->refresh()->actual_cost);
    }

    public function test_crew_members_see_the_task_and_can_charge_expenses_to_it(): void
    {
        $entry = $this->schedule(['title' => 'Livestream hội thảo', 'task_budget' => 5000000], [$this->quay, $this->sang]);
        $task = $entry->task;
        $someoneElse = Task::factory()->create(['name' => 'Việc của người khác']);

        // Sáng không phải người phụ trách nhưng có trong ê-kíp.
        $this->actingAs($this->sang);

        $this->assertTrue($this->sang->can('view', $task));
        $this->assertFalse($this->sang->can('view', $someoneElse));
        $this->assertSame([$task->getKey()], Task::query()->visibleTo($this->sang)->pluck('id')->all());

        Livewire::withQueryParams(['task' => $task->getKey()])
            ->test(CreateExpense::class)
            ->assertSchemaStateSet(['task_id' => $task->getKey()])
            ->fillForm([
                'category' => BudgetCategory::Travel->value,
                'description' => 'Taxi chở thiết bị',
                'amount' => 350000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::query()->sole();

        $this->assertSame($task->getKey(), $expense->task_id);
        $this->get(ExpenseResource::getUrl('view', ['record' => $expense]))->assertOk()->assertSee('Livestream hội thảo');

        // Nhân viên mở được trang công việc nhưng không thấy tiền.
        $this->get(TaskResource::getUrl('view', ['record' => $task]))
            ->assertOk()
            ->assertSee('Livestream hội thảo')
            ->assertDontSee('Tiền &amp; ngân sách', false)
            ->assertDontSee('5.000.000');
        $this->get(TaskResource::getUrl('view', ['record' => $someoneElse]))->assertNotFound();

        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task])
            ->assertCanNotSeeTableRecords([$someoneElse])
            ->assertDontSee('Chi phí / ngân sách');
    }

    public function test_task_page_is_where_the_money_of_a_job_is_managed(): void
    {
        $entry = $this->schedule(['title' => 'Livestream hội thảo', 'task_budget' => 5000000], [$this->quay], '2026-10-06 08:00', '2026-10-06 12:00');
        $task = $entry->task;
        // 6.000.000 đề nghị chi + công của Quay tự tính (1 buổi × 150.000).
        $expense = $this->payExpense($this->makeExpenseFor($task, 6000000));

        $this->actingAs($this->pm);

        $this->get(TaskResource::getUrl('view', ['record' => $task]))
            ->assertOk()
            ->assertSee('Tiền &amp; ngân sách', false)
            ->assertSee('5.000.000 ₫')
            ->assertSee('6.150.000 ₫')
            ->assertSee('Trong đó: nhân sự')
            ->assertSee('1 buổi (công) của 1 người')
            ->assertSee('Vượt ngân sách');

        // Công việc đã huỷ thì không mời tạo thêm chứng từ và không còn công tự sinh; việc đã xong vẫn nhận chi phí về sau.
        $task->update(['status' => TaskStatus::Cancelled]);

        $this->assertSame(0, $task->timesheets()->count());
        $this->assertSame('6000000.00', $task->refresh()->actual_cost);

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionHidden('createExpense')
            ->assertActionVisible('edit');

        $task->update(['status' => TaskStatus::Done]);

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionVisible('createExpense')
            ->assertActionVisible('createPurchaseRequest')
            ->callAction('edit', data: ['budget' => 8000000])
            ->assertHasNoActionErrors();

        $this->assertSame('8000000.00', $task->refresh()->budget);
        $this->assertFalse($task->isOverBudget());

        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertCanSeeTableRecords([$expense]);
        Livewire::test(ScheduleRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertCanSeeTableRecords([$entry]);

        // Mở lại công việc: công của Quay được tính lại. Thêm công cho người ngoài ê-kíp ngay trên trang công việc.
        $this->assertSame('6150000.00', $task->refresh()->actual_cost);

        Livewire::test(TimesheetsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertCountTableRecords(1)
            ->assertSee('Tự động')
            ->callAction(TestAction::make('create')->table(), data: [
                'user_id' => $this->sang->getKey(),
                'work_date' => '2026-10-06',
                'sessions' => 2,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, $task->timesheets()->count());
        $this->assertSame('6350000.00', $task->refresh()->actual_cost);

        // Danh sách công việc có cột tiền; báo cáo chi phí ghi công việc.
        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task])
            ->assertSee('Chi phí / ngân sách')
            ->assertSee('6.350.000 ₫')
            ->assertSee('Ngân sách 8.000.000 ₫');

        $this->actingAs($this->approver(SystemRole::Accountant));

        Livewire::test(CostReport::class)->assertSee('Livestream hội thảo');
    }

    public function test_purchase_request_for_a_task_passes_the_task_to_its_order(): void
    {
        // Chưa phân công ai nên chưa có công: chỉ xét chi phí mua hàng.
        $task = $this->schedule(['title' => 'Show ca nhạc'])->task;
        $procurement = $this->approver(SystemRole::Procurement);

        $this->actingAs($procurement);

        Livewire::withQueryParams(['task' => $task->getKey()])
            ->test(CreatePurchaseRequest::class)
            ->assertSchemaStateSet(['task_id' => $task->getKey()])
            ->fillForm(['description' => 'Thuê màn hình LED', 'amount' => 4000000])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = PurchaseRequest::query()->sole();
        $requests = app(PurchaseRequestWorkflow::class);

        $this->assertSame($task->getKey(), $request->task_id);

        $request = $requests->submit($request, $procurement);
        $request = $requests->approve($request, $this->pm);
        $requests->approve($request, $this->approver(SystemRole::Accountant));

        // Yêu cầu đã duyệt là chi phí đã cam kết của công việc.
        $this->assertSame('4000000.00', $task->refresh()->committed_cost);
        $this->assertSame('0.00', $task->actual_cost);

        // Đơn mua hàng lập từ yêu cầu nhận luôn công việc đó; nhận hàng thì thành chi phí thực tế.
        $undoRepeaterFake = Repeater::fake();

        Livewire::withQueryParams(['request' => $request->getKey()])
            ->test(CreatePurchaseOrder::class)
            ->assertSchemaStateSet(['task_id' => $task->getKey()])
            ->fillForm([
                'supplier_id' => Supplier::factory()->create()->getKey(),
                'items' => [
                    ['description' => 'Màn hình LED P3', 'quantity' => 1, 'unit_price' => 3800000, 'tax_rate' => 10],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $order = PurchaseOrder::query()->sole();
        $orders = app(PurchaseOrderWorkflow::class);

        $this->assertSame($task->getKey(), $order->task_id);

        $orders->receive($orders->order($order, $procurement), $procurement);

        $this->assertSame('3800000.00', $task->refresh()->actual_cost);
        $this->assertSame('3800000.00', $task->committed_cost);
    }

    public function test_backfill_creates_tasks_for_schedules_made_before_this_feature(): void
    {
        $past = $this->makeEntry('2026-10-05 08:00', '2026-10-05 12:00', [$this->quay], ['title' => 'Quay TVC']);
        $future = $this->makeEntry('2026-10-09 08:00', '2026-10-09 12:00', [$this->sang], ['title' => 'Livestream']);
        $cancelled = $this->makeEntry('2026-10-09 13:00', '2026-10-09 17:00', [], ['title' => 'Khảo sát', 'status' => ScheduleStatus::Cancelled]);
        $leave = $this->makeEntry('2026-10-09 08:00', '2026-10-09 12:00', [$this->quay], ['schedule_type_id' => ScheduleType::leave()->getKey()]);

        // Công đã chấm từ lịch (bản trước) khi lịch chưa có công việc: 4 buổi × 150.000.
        $past->timesheets()->attach(Timesheet::factory()->create(['user_id' => $this->quay->getKey(), 'work_date' => '2026-10-05', 'sessions' => 4])->getKey());
        ScheduleEntry::query()->whereKey($past->getKey())->update(['status' => ScheduleStatus::Done]);
        $this->assertNull(Timesheet::query()->sole()->task_id);

        $this->assertSame(3, app(ScheduleTasks::class)->backfill());
        // Chạy lại không tạo trùng.
        $this->assertSame(0, app(ScheduleTasks::class)->backfill());

        $pastTask = $past->refresh()->task;

        $this->assertSame('Quay TVC', $pastTask->name);
        $this->assertSame(TaskStatus::Done, $pastTask->status);
        $this->assertSame($this->quay->getKey(), $pastTask->assignee_id);
        // Người / ngày đã chấm công từ trước thì giữ dòng cũ, không tính thêm công tự sinh.
        $this->assertSame($pastTask->getKey(), $pastTask->timesheets()->sole()->task_id);
        $this->assertFalse($pastTask->timesheets()->sole()->is_auto);
        $this->assertSame('600000.00', $pastTask->actual_cost);
        $this->assertSame('4.00', $pastTask->actual_sessions);
        // Lịch chưa chấm công: công tự sinh theo ê-kíp (Sáng, 1 buổi × 100.000).
        $this->assertSame('100000.00', $future->refresh()->task->actual_cost);

        $this->assertSame(TaskStatus::Todo, $future->refresh()->task->status);
        $this->assertSame(TaskStatus::Cancelled, $cancelled->refresh()->task->status);
        $this->assertNull($leave->refresh()->task_id);
    }

    /**
     * Xếp một lịch qua ScheduleManager (như trang Lịch làm việc), do quản lý công việc thực hiện.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<User>  $crew
     */
    private function schedule(array $attributes, array $crew = [], string $from = '2026-10-08 08:00', string $to = '2026-10-08 12:00'): ScheduleEntry
    {
        return app(ScheduleManager::class)->save(
            null,
            [...$this->attributes($from, $to), ...$attributes],
            array_map(fn (User $user): int => $user->getKey(), $crew),
            $this->pm,
        )->load('task');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $from = '2026-10-08 08:00', string $to = '2026-10-08 12:00'): array
    {
        return [
            'title' => 'Lịch thử',
            'schedule_type_id' => ScheduleType::query()->where('is_leave', false)->orderBy('sort_order')->value('id'),
            'status' => ScheduleStatus::Confirmed,
            'starts_at' => CarbonImmutable::parse($from),
            'ends_at' => CarbonImmutable::parse($to),
        ];
    }

    private function makeExpenseFor(Task $task, int $amount = 1000000): Expense
    {
        return Expense::factory()->create([
            'task_id' => $task->getKey(),
            'employee_id' => $this->quay->getKey(),
            'amount' => $amount,
        ])->refresh();
    }
}
