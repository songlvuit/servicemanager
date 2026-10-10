<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Services\Contracts\ContractWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use BuildsJobs;
    use BuildsQuotations;
    use RefreshDatabase;

    private User $accountant;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->accountant = $this->createUserWithRole(SystemRole::Accountant);
        $this->director = $this->createUserWithRole(SystemRole::Director);
    }

    public function test_contract_gets_a_code_and_total_includes_tax(): void
    {
        $contract = Contract::factory()->create(['contract_value' => 250000000, 'tax_amount' => 25000000]);

        $this->assertSame('HD-'.now()->year.'-0001', $contract->code);
        $this->assertSame(ContractStatus::Draft, $contract->status);
        $this->assertSame('275000000.00', $contract->refresh()->total_value);

        $contract->update(['tax_amount' => 20000000]);

        $this->assertSame('270000000.00', $contract->refresh()->total_value);
    }

    public function test_accountant_creates_a_contract_from_an_accepted_quotation(): void
    {
        $quotation = $this->makeQuotation(creator: $this->accountant);
        $quotation = $this->workflow()->submit($quotation, $this->accountant);
        $quotation = $this->workflow()->approve($quotation, $this->director);
        $quotation = $this->workflow()->markSent($quotation, $this->accountant);
        $quotation = $this->workflow()->markAccepted($quotation, $this->accountant);

        $this->actingAs($this->accountant);

        // Trang báo giá có nút "Tạo hợp đồng".
        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionVisible('createContract');

        // Form hợp đồng mở từ báo giá được điền sẵn khách hàng, tiêu đề và giá trị.
        Livewire::withQueryParams(['quotation' => $quotation->getKey()])
            ->test(CreateContract::class)
            ->assertSchemaStateSet([
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->getKey(),
                'title' => $quotation->title,
                'contract_value' => '10000000',
                'tax_amount' => '1000000',
            ])
            ->fillForm(['start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString()])
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = Contract::query()->where('quotation_id', $quotation->getKey())->first();

        $this->assertNotNull($contract);
        $this->assertSame($this->accountant->getKey(), $contract->created_by);
        $this->assertSame('10000000.00', $contract->contract_value);
        $this->assertSame('11000000.00', $contract->total_value);

        // Báo giá đã có hợp đồng thì không còn nút tạo hợp đồng.
        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionHidden('createContract');
    }

    public function test_contract_form_rejects_a_quotation_that_is_not_accepted(): void
    {
        $draftQuotation = $this->makeQuotation(creator: $this->accountant);

        $this->actingAs($this->accountant);

        Livewire::test(CreateContract::class)
            ->fillForm([
                'customer_id' => $draftQuotation->customer_id,
                'quotation_id' => $draftQuotation->getKey(),
                'title' => 'Hợp đồng thử',
                'contract_value' => 1000000,
                'tax_amount' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['quotation_id']);

        $this->assertSame(0, Contract::query()->count());
    }

    public function test_contract_moves_from_draft_to_active_and_activates_the_customer(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Lead]);
        $contract = Contract::factory()->create(['customer_id' => $customer->getKey()]);
        $workflow = app(ContractWorkflow::class);

        $contract = $workflow->sendForSignature($contract, $this->accountant);
        $this->assertSame(ContractStatus::PendingSignature, $contract->status);

        $contract = $workflow->activate($contract, $this->director, today()->subDay());

        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertTrue($contract->sign_date->isSameDay(today()->subDay()));
        $this->assertSame($this->director->getKey(), $contract->activated_by);
        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    public function test_only_approvers_can_activate_a_contract(): void
    {
        $contract = Contract::factory()->pendingSignature()->create();

        $this->expectException(AuthorizationException::class);

        app(ContractWorkflow::class)->activate($contract, $this->accountant);
    }

    public function test_contract_without_value_cannot_be_sent_for_signature(): void
    {
        $contract = Contract::factory()->create(['contract_value' => 0, 'tax_amount' => 0]);

        $this->expectException(WorkflowException::class);

        app(ContractWorkflow::class)->sendForSignature($contract, $this->accountant);
    }

    public function test_invalid_status_changes_are_rejected(): void
    {
        $contract = Contract::factory()->create();

        $this->expectException(WorkflowException::class);

        // Hợp đồng nháp chưa gửi ký thì chưa thể có hiệu lực.
        app(ContractWorkflow::class)->activate($contract, $this->director);
    }

    public function test_contract_with_open_tasks_cannot_be_completed_or_cancelled(): void
    {
        $contract = $this->makeActiveContract();
        $task = Task::factory()->create(['contract_id' => $contract->getKey()]);
        $workflow = app(ContractWorkflow::class);

        try {
            $workflow->complete($contract, $this->director);
            $this->fail('Hợp đồng còn công việc đang mở không được hoàn thành.');
        } catch (WorkflowException) {
            $this->assertSame(ContractStatus::Active, $contract->refresh()->status);
        }

        try {
            $workflow->cancel($contract, $this->director, 'Khách hàng dừng hợp tác.');
            $this->fail('Hợp đồng còn công việc đang mở không được huỷ.');
        } catch (WorkflowException) {
            $this->assertSame(ContractStatus::Active, $contract->refresh()->status);
        }

        $task->update(['status' => TaskStatus::Done]);

        $this->assertSame(ContractStatus::Completed, $workflow->complete($contract, $this->director)->status);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $contract = Contract::factory()->create();
        $workflow = app(ContractWorkflow::class);

        try {
            $workflow->cancel($contract, $this->accountant, '   ');
            $this->fail('Huỷ hợp đồng phải có lý do.');
        } catch (WorkflowException) {
            $this->assertSame(ContractStatus::Draft, $contract->refresh()->status);
        }

        $contract = $workflow->cancel($contract, $this->accountant, 'Khách hàng đổi kế hoạch.');

        $this->assertSame(ContractStatus::Cancelled, $contract->status);
        $this->assertSame('Khách hàng đổi kế hoạch.', $contract->cancel_reason);
    }

    public function test_only_draft_contracts_can_be_edited_even_by_super_admin(): void
    {
        $contract = Contract::factory()->pendingSignature()->create();

        $this->actingAs($this->accountant)
            ->get(ContractResource::getUrl('edit', ['record' => $contract]))
            ->assertForbidden();

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->assertFalse(ContractResource::canEdit($contract));
        $this->assertFalse(ContractResource::canDelete($contract));
    }

    public function test_workflow_buttons_follow_status_and_permissions(): void
    {
        $contract = Contract::factory()->create();

        $this->actingAs($this->accountant);

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->assertActionVisible('sendForSignature')
            ->assertActionHidden('activate')
            ->callAction('sendForSignature');

        $this->assertSame(ContractStatus::PendingSignature, $contract->refresh()->status);

        // Kế toán không xác nhận hiệu lực được; Giám đốc thì được.
        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->assertActionHidden('activate');

        $this->actingAs($this->director);

        Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
            ->assertActionVisible('activate')
            ->callAction('activate', data: ['sign_date' => today()->toDateString()])
            ->assertHasNoFormErrors();

        $this->assertSame(ContractStatus::Active, $contract->refresh()->status);
    }

    public function test_contract_pages_respect_permissions(): void
    {
        $contract = Contract::factory()->create();

        $this->actingAs($this->accountant);

        $this->get(ContractResource::getUrl('index'))->assertOk()->assertSee($contract->code);
        $this->get(ContractResource::getUrl('create'))->assertOk();
        $this->get(ContractResource::getUrl('view', ['record' => $contract]))->assertOk()->assertSee('110.000.000');
        $this->get(ContractResource::getUrl('edit', ['record' => $contract]))->assertOk();

        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(ContractResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_expiring_contracts_are_found(): void
    {
        $expiring = $this->makeActiveContract(['end_date' => today()->addDays(10)]);
        $this->makeActiveContract(['end_date' => today()->addDays(90)]);
        Contract::factory()->create(['end_date' => today()->addDays(5)]); // nháp: không tính

        $found = Contract::query()->expiringWithin(30)->pluck('id')->all();

        $this->assertSame([$expiring->getKey()], $found);
        $this->assertTrue($expiring->isExpiringSoon());
    }
}
