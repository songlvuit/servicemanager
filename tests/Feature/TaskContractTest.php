<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\WorkCalendar;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\TaskBoard;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ContractLink;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

/**
 * Công việc ↔ hợp đồng / báo giá.
 *
 * Phần mềm không còn "dự án": mỗi công việc là một việc độc lập, có thể gắn
 * với một hợp đồng và một báo giá. Mọi nội dung về hợp đồng, báo giá chỉ người
 * có quyền xem Hợp đồng / Báo giá thấy — mặc định là Giám đốc và Kế toán.
 */
class TaskContractTest extends TestCase
{
    use BuildsJobs;
    use BuildsQuotations;
    use RefreshDatabase;

    private User $director;

    private User $accountant;

    private User $pm;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->director = $this->createUserWithRole(SystemRole::Director);
        $this->accountant = $this->createUserWithRole(SystemRole::Accountant);
        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
        $this->employee = $this->createUserWithRole(SystemRole::Employee);
    }

    public function test_director_creates_a_task_linked_to_a_contract_and_quotation(): void
    {
        $quotation = $this->makeQuotation(creator: $this->accountant);
        $contract = $this->makeActiveContract([
            'title' => 'Sản xuất TVC mùa thu',
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->getKey(),
        ]);

        $this->actingAs($this->director);

        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'name' => 'Quay TVC ngày 1',
                'contract_id' => $contract->getKey(),
                'budget' => 15000000,
            ])
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();

        // Báo giá và khách hàng đi theo hợp đồng.
        $this->assertSame($contract->getKey(), $task->contract_id);
        $this->assertSame($quotation->getKey(), $task->quotation_id);
        $this->assertSame($contract->customer_id, $task->customer_id);
        $this->assertSame('15000000.00', $contract->refresh()->budget);

        // Công việc con luôn theo hợp đồng, báo giá của công việc cha — kể cả khi cha đổi.
        $child = Task::factory()->create(['parent_id' => $task->getKey()]);

        $this->assertSame($contract->getKey(), $child->refresh()->contract_id);
        $this->assertSame($quotation->getKey(), $child->quotation_id);

        $other = $this->makeActiveContract();
        $task->linkToContract(['contract_id' => $other->getKey(), 'quotation_id' => null]);

        $this->assertSame($other->getKey(), $child->refresh()->contract_id);
        $this->assertNull($child->quotation_id);
        $this->assertSame($other->customer_id, $task->refresh()->customer_id);
        $this->assertSame('0.00', $contract->refresh()->budget);
        $this->assertSame('15000000.00', $other->refresh()->budget);

        // Công việc chỉ gắn báo giá (chưa có hợp đồng) lấy khách hàng của báo giá.
        $quoted = Task::factory()->create(['quotation_id' => $quotation->getKey()]);

        $this->assertNull($quoted->contract_id);
        $this->assertSame($quotation->customer_id, $quoted->refresh()->customer_id);
    }

    public function test_director_and_accountant_see_the_contract_on_tasks(): void
    {
        [$task, $contract, $quotation] = $this->linkedTask();

        foreach ([$this->director, $this->accountant] as $user) {
            $this->actingAs($user);

            $this->assertTrue(ContractLink::canSeeContracts());
            $this->assertTrue(ContractLink::canSeeQuotations());

            $this->get(TaskResource::getUrl('view', ['record' => $task]))
                ->assertOk()
                ->assertSee('Hợp đồng & báo giá')
                ->assertSee($contract->code)
                ->assertSee('Sản xuất TVC bí mật')
                ->assertSee($quotation->code);

            Livewire::test(ManageTasks::class)
                ->set('activeTab', 'all')
                ->assertTableColumnVisible('contract.code')
                ->assertSee($contract->code)
                ->filterTable('contract_id', $contract->getKey())
                ->assertCanSeeTableRecords([$task])
                ->removeTableFilter('contract_id')
                ->filterTable('without_contract')
                ->assertCanNotSeeTableRecords([$task]);
        }
    }

    /**
     * Người không có quyền xem hợp đồng / báo giá không thấy gì về chúng ở
     * công việc: không cột, không ô nhập, không dòng trên trang chi tiết, và
     * cả trong dữ liệu form gửi xuống trình duyệt cũng không có.
     */
    public function test_contract_and_quotation_are_invisible_to_everyone_else(): void
    {
        [$task, $contract, $quotation] = $this->linkedTask(['assignee_id' => $this->employee->getKey()]);
        $secrets = [$contract->code, 'Sản xuất TVC bí mật', $quotation->code, $quotation->title, 'Hợp đồng & báo giá', 'Hợp đồng', 'Báo giá'];

        foreach ([$this->pm, $this->employee] as $user) {
            $this->actingAs($user);

            $this->assertFalse(ContractLink::canSee());
            $this->assertSame([], ContractLink::fields());
            $this->assertSame([], ContractLink::contractOptions());
            $this->assertSame([], ContractLink::quotationOptions());
            $this->assertNull(ContractLink::formSection());
            $this->assertNull(ContractLink::infolistSection());

            $page = $this->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()->assertSee('Quay TVC ngày 1');
            $list = $this->get(TaskResource::getUrl('index', ['tab' => 'all']))->assertOk()->assertSee('Quay TVC ngày 1');
            $board = $this->get(TaskResource::getUrl('board'))->assertOk()->assertSee('Quay TVC ngày 1');
            $calendar = $this->get(WorkCalendar::getUrl(['date' => $task->start_date->toDateString()]))->assertOk()->assertSee('Quay TVC ngày 1');

            foreach ($secrets as $secret) {
                $page->assertDontSee($secret);
                $list->assertDontSee($secret);
                $board->assertDontSee($secret);
                $calendar->assertDontSee($secret);
            }

            Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
                ->assertActionHidden('linkContract');

            // Mở form sửa: không có ô hợp đồng / báo giá, và trạng thái form không mang hai giá trị này.
            $component = Livewire::test(ManageTasks::class)
                ->set('activeTab', 'all')
                ->assertTableColumnDoesNotExist('contract.code')
                ->mountAction(TestAction::make('edit')->table($task));

            $state = $component->instance()->mountedActions[0]['data'] ?? [];

            $this->assertArrayNotHasKey('contract_id', $state);
            $this->assertArrayNotHasKey('quotation_id', $state);
            $this->assertStringNotContainsString($contract->code, $component->html());
            $this->assertStringNotContainsString('contract_id', $component->html());

            // Các trang hợp đồng, báo giá đều bị chặn.
            $this->get(ContractResource::getUrl('index'))->assertForbidden();
            $this->get(ContractResource::getUrl('view', ['record' => $contract]))->assertForbidden();
            $this->get(QuotationResource::getUrl('index'))->assertForbidden();
            $this->get(QuotationResource::getUrl('view', ['record' => $quotation]))->assertForbidden();
        }
    }

    public function test_people_without_access_cannot_change_or_lose_the_link(): void
    {
        [$task, $contract, $quotation] = $this->linkedTask();
        $other = $this->makeActiveContract();

        $this->actingAs($this->pm);

        // Sửa công việc (kể cả cố gửi kèm contract_id): hợp đồng, báo giá giữ nguyên.
        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->callAction(TestAction::make('edit')->table($task), [
                'name' => 'Quay TVC ngày 1 (đổi lịch)',
                'status' => TaskStatus::InProgress->value,
                'contract_id' => $other->getKey(),
                'quotation_id' => null,
            ])
            ->assertHasNoFormErrors();

        $task->refresh();

        $this->assertSame('Quay TVC ngày 1 (đổi lịch)', $task->name);
        $this->assertSame($contract->getKey(), $task->contract_id);
        $this->assertSame($quotation->getKey(), $task->quotation_id);

        // Tạo công việc mới cũng không gắn được hợp đồng.
        Livewire::test(ManageTasks::class)
            ->callAction('create', data: ['name' => 'Việc mới của quản lý', 'contract_id' => $other->getKey()])
            ->assertHasNoFormErrors();

        $this->assertNull(Task::query()->where('name', 'Việc mới của quản lý')->sole()->contract_id);

        // Bảng Kanban: sửa thẻ cũng vậy.
        Livewire::test(TaskBoard::class)
            ->callAction(TestAction::make('editTask')->arguments(['task' => $task->getKey()]), [
                'name' => 'Quay TVC ngày 1',
                'contract_id' => $other->getKey(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame($contract->getKey(), $task->refresh()->contract_id);
        $this->assertSame('Quay TVC ngày 1', $task->name);
    }

    public function test_accountant_links_a_task_to_a_contract_from_the_task_page(): void
    {
        $contract = $this->makeActiveContract();
        $task = Task::factory()->create(['name' => 'Livestream hội thảo', 'budget' => 8000000]);
        $child = Task::factory()->create(['parent_id' => $task->getKey()]);

        // Kế toán không sửa được công việc, nhưng gắn được hợp đồng vì đó là thông tin của phía hợp đồng.
        $this->assertFalse($this->accountant->can('update', $task));

        $this->actingAs($this->accountant);

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionVisible('linkContract')
            ->callAction('linkContract', data: ['contract_id' => $contract->getKey()])
            ->assertHasNoFormErrors()
            ->assertNotified('Đã lưu hợp đồng & báo giá của công việc.');

        $this->assertSame($contract->getKey(), $task->refresh()->contract_id);
        $this->assertSame($contract->getKey(), $child->refresh()->contract_id);
        $this->assertSame($contract->customer_id, $task->customer_id);
        $this->assertSame('8000000.00', $contract->refresh()->budget);

        // Công việc con: phải gắn ở công việc cha.
        Livewire::test(ViewTask::class, ['record' => $child->getRouteKey()])
            ->callAction('linkContract', data: ['contract_id' => null])
            ->assertNotified('Đây là công việc con: hãy gắn hợp đồng ở công việc cha.');

        $this->assertSame($contract->getKey(), $child->refresh()->contract_id);

        // Gỡ hợp đồng.
        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->callAction('linkContract', data: ['contract_id' => null])
            ->assertHasNoFormErrors();

        $this->assertNull($task->refresh()->contract_id);
        $this->assertNull($child->refresh()->contract_id);
        $this->assertSame('0.00', $contract->refresh()->budget);
    }

    public function test_contract_page_lists_and_manages_its_tasks(): void
    {
        $contract = $this->makeActiveContract(['contract_value' => 100000000]);
        $linked = $this->makeJob(['name' => 'Quay TVC', 'budget' => 20000000], $contract);
        $free = Task::factory()->create(['name' => 'Chụp ảnh sản phẩm', 'budget' => 5000000]);
        $elsewhere = $this->makeJob(['name' => 'Việc của hợp đồng khác']);

        $this->actingAs($this->director);

        $this->get(ContractResource::getUrl('view', ['record' => $contract]))
            ->assertOk()
            ->assertSee('Chi phí & lợi nhuận');

        $tab = fn () => Livewire::test(TasksRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class]);

        $tab()
            ->assertCanSeeTableRecords([$linked])
            ->assertCanNotSeeTableRecords([$free, $elsewhere])
            // Chỉ nhận việc chưa thuộc hợp đồng nào.
            ->callAction(TestAction::make('attachTasks')->table(), ['tasks' => [$elsewhere->getKey()]])
            ->assertHasFormErrors();

        $this->assertNotSame($contract->getKey(), $elsewhere->refresh()->contract_id);

        // Gắn một công việc có sẵn.
        $tab()
            ->callAction(TestAction::make('attachTasks')->table(), ['tasks' => [$free->getKey()]])
            ->assertHasNoFormErrors();

        $this->assertSame($contract->getKey(), $free->refresh()->contract_id);
        $this->assertSame('25000000.00', $contract->refresh()->budget);

        // Thêm công việc mới ngay trong hợp đồng.
        $tab()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Dựng phim', 'budget' => 10000000])
            ->assertHasNoFormErrors();

        $created = Task::query()->where('name', 'Dựng phim')->sole();

        $this->assertSame($contract->getKey(), $created->contract_id);
        $this->assertSame($contract->customer_id, $created->customer_id);
        $this->assertSame('35000000.00', $contract->refresh()->budget);

        // Gỡ khỏi hợp đồng: công việc còn nguyên.
        $tab()->callAction(TestAction::make('detach')->table($free))->assertHasNoErrors();

        $this->assertNull($free->refresh()->contract_id);
        $this->assertSame('30000000.00', $contract->refresh()->budget);

        // Kế toán xem được tab, gắn / gỡ được, nhưng không tạo / xoá công việc.
        $this->actingAs($this->accountant);

        $tab()
            ->assertCanSeeTableRecords([$linked, $created])
            ->assertActionVisible(TestAction::make('attachTasks')->table())
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('delete')->table($linked));
    }

    public function test_sales_no_longer_sees_quotations_or_contracts_anywhere(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);
        $quotation = $this->makeQuotation(creator: $this->accountant);
        $contract = $this->makeActiveContract(['customer_id' => $quotation->customer_id, 'title' => 'Sản xuất TVC bí mật']);

        $this->actingAs($sales);

        $this->get(QuotationResource::getUrl('index'))->assertForbidden();
        $this->get(QuotationResource::getUrl('create'))->assertForbidden();
        $this->get(ContractResource::getUrl('index'))->assertForbidden();
        $this->get(TaskResource::getUrl('index'))->assertForbidden();

        // Trang khách hàng: không còn tab / cột Báo giá, Hợp đồng, Hóa đơn.
        $this->get(CustomerResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('Báo giá');

        $this->get(CustomerResource::getUrl('view', ['record' => $quotation->customer_id]))
            ->assertOk()
            ->assertDontSee('Báo giá')
            ->assertDontSee('Hợp đồng')
            ->assertDontSee('Hóa đơn')
            ->assertDontSee($quotation->code)
            ->assertDontSee($contract->code)
            ->assertDontSee('Sản xuất TVC bí mật');

        // Kế toán thì thấy đủ.
        $this->actingAs($this->accountant)
            ->get(CustomerResource::getUrl('view', ['record' => $quotation->customer_id]))
            ->assertOk()
            ->assertSee('Báo giá')
            ->assertSee('Hợp đồng');
    }

    public function test_contract_cannot_be_closed_while_it_has_open_tasks(): void
    {
        $contract = $this->makeActiveContract();
        $task = $this->makeJob([], $contract);

        $this->actingAs($this->director);

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->callAction('complete')
            ->assertNotified();

        $this->assertTrue($contract->refresh()->isActive());

        $task->update(['status' => TaskStatus::Done]);

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])->callAction('complete');

        $this->assertFalse($contract->refresh()->isActive());
    }

    /**
     * Một công việc đã gắn hợp đồng "Sản xuất TVC bí mật" và báo giá của nó.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Task, 1: Contract, 2: Quotation}
     */
    private function linkedTask(array $attributes = []): array
    {
        $quotation = $this->makeQuotation(creator: $this->accountant);
        $contract = $this->makeActiveContract([
            'title' => 'Sản xuất TVC bí mật',
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->getKey(),
        ]);

        $task = $this->makeJob(['name' => 'Quay TVC ngày 1', 'start_date' => today()->addDay(), 'budget' => 15000000, ...$attributes], $contract);

        return [$task, $contract, $quotation];
    }
}
