<?php

namespace Tests\Feature;

use App\Enums\AcceptanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Filament\Resources\Acceptances\Pages\ViewAcceptance;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Acceptance;
use App\Models\Invoice;
use App\Services\Billing\AcceptanceWorkflow;
use App\Services\Billing\InvoiceWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use BuildsInvoices;
    use BuildsProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_invoice_amounts_are_calculated_from_subtotal_and_tax_rate(): void
    {
        $invoice = $this->makeInvoice(['subtotal' => 25500000, 'tax_rate' => 8]);

        $this->assertSame('INV-'.now()->year.'-0001', $invoice->code);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('2040000.00', $invoice->tax);
        $this->assertSame('27540000.00', $invoice->total);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('27540000.00', $invoice->remaining_amount);

        // Thuế làm tròn tới 2 số lẻ: 1.234.567 × 10% = 123.456,70.
        $invoice->update(['subtotal' => 1234567, 'tax_rate' => 10]);

        $this->assertSame('123456.70', $invoice->refresh()->tax);
        $this->assertSame('1358023.70', $invoice->total);
        $this->assertSame('1358023.70', $invoice->remaining_amount);
    }

    public function test_issuing_an_invoice_sets_its_status_from_the_due_date(): void
    {
        $workflow = app(InvoiceWorkflow::class);

        $invoice = $workflow->issue($this->makeInvoice(), $this->accountant());

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNotNull($invoice->issued_at);

        // Hóa đơn nhập bù có hạn thanh toán đã qua: quá hạn ngay khi phát hành.
        $late = $workflow->issue(
            $this->makeInvoice(['invoice_date' => today()->subDays(40), 'due_date' => today()->subDays(10)]),
            $this->accountant(),
        );

        $this->assertSame(InvoiceStatus::Overdue, $late->status);
        $this->assertSame(10, $late->daysOverdue());

        // Không phát hành hai lần, không phát hành hóa đơn 0 đồng.
        try {
            $workflow->issue($invoice, $this->accountant());
            $this->fail('Hóa đơn đã phát hành không phát hành lại được.');
        } catch (WorkflowException) {
            $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        }

        $this->expectException(WorkflowException::class);

        $workflow->issue($this->makeInvoice(['subtotal' => 0]), $this->accountant());
    }

    /**
     * Rule 3: không thể đánh dấu hóa đơn đã thu đủ khi còn phải thu.
     */
    public function test_invoice_cannot_be_marked_paid_while_money_is_outstanding(): void
    {
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $this->issuedInvoice();
        $this->pay($invoice, 6000000);

        try {
            $workflow->markPaid($invoice, $this->accountant());
            $this->fail('Rule 3: còn phải thu 5.000.000 thì chưa thể đánh dấu đã thu đủ.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('còn phải thu', $exception->getMessage());
            $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->refresh()->status);
        }

        $this->pay($invoice, 5000000);

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    /**
     * Rule 4: không thể xoá hóa đơn đã phát hành.
     */
    public function test_issued_invoice_cannot_be_deleted(): void
    {
        $draft = $this->makeInvoice();
        $issued = $this->issuedInvoice();
        $accountant = $this->accountant();

        $this->assertTrue($accountant->can('delete', $draft));
        $this->assertFalse($accountant->can('delete', $issued));
        $this->assertFalse($accountant->can('update', $issued));

        // Kể cả Super Admin (bỏ qua policy) cũng không xoá / sửa được.
        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->assertFalse(InvoiceResource::canDelete($issued));
        $this->assertFalse(InvoiceResource::canEdit($issued));

        try {
            $issued->delete();
            $this->fail('Rule 4: hóa đơn đã phát hành không xoá được.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('đã phát hành', $exception->getMessage());
        }

        $this->assertNotNull(Invoice::query()->find($issued->getKey()));

        // Hóa đơn nháp thì xoá (mềm) được.
        $draft->delete();
        $this->assertSoftDeleted($draft);
    }

    public function test_only_unpaid_invoices_can_be_cancelled_with_a_reason(): void
    {
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $this->issuedInvoice();

        try {
            $workflow->cancel($invoice, $this->accountant(), '');
            $this->fail('Huỷ hóa đơn phải có lý do.');
        } catch (WorkflowException) {
            $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        }

        $cancelled = $workflow->cancel($invoice, $this->accountant(), 'Lập sai thông tin khách hàng.');

        $this->assertSame(InvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('Lập sai thông tin khách hàng.', $cancelled->cancel_reason);

        // Hóa đơn đã có thanh toán thì không huỷ được.
        $partlyPaid = $this->issuedInvoice();
        $this->pay($partlyPaid, 1000000);

        $this->expectException(WorkflowException::class);

        $workflow->cancel($partlyPaid, $this->accountant(), 'Thử huỷ.');
    }

    public function test_overdue_invoices_are_marked_by_the_daily_job(): void
    {
        $dueTomorrow = $this->issuedInvoice(['due_date' => today()->addDay()]);
        $dueToday = $this->issuedInvoice(['due_date' => today()]);
        $partlyPaid = $this->issuedInvoice(['due_date' => today()->addDays(2)]);
        $this->pay($partlyPaid, 1000000);
        $draft = $this->makeInvoice(['invoice_date' => today()->subDays(20), 'due_date' => today()->subDays(5)]);

        // 3 ngày sau: hai hóa đơn đầu và hóa đơn thu một phần đã quá hạn.
        $this->travel(3)->days();

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $dueTomorrow->refresh()->status);
        $this->assertSame(InvoiceStatus::Overdue, $dueToday->refresh()->status);
        $this->assertSame(InvoiceStatus::Overdue, $partlyPaid->refresh()->status);
        $this->assertSame(InvoiceStatus::Draft, $draft->refresh()->status);
        $this->assertSame(2, $dueTomorrow->daysOverdue());
        $this->assertSame(3, Invoice::query()->pastDue()->count());

        // Thu đủ hóa đơn quá hạn thì thành "Đã thu đủ".
        $this->pay($dueToday, $dueToday->remaining_amount);
        $this->assertSame(InvoiceStatus::Paid, $dueToday->refresh()->status);
        $this->assertSame(0, $dueToday->daysOverdue());
    }

    public function test_acceptance_is_approved_and_turned_into_an_invoice(): void
    {
        $pm = $this->createUserWithRole(SystemRole::ProjectManager);
        $director = $this->createUserWithRole(SystemRole::Director);
        $project = $this->makeProject();
        $workflow = app(AcceptanceWorkflow::class);

        $acceptance = Acceptance::factory()->create(['project_id' => $project->getKey(), 'amount' => 40000000, 'description' => 'Nghiệm thu đợt 1']);
        $this->assertSame('NT-'.now()->year.'-0001', $acceptance->code);

        $acceptance = $workflow->submit($acceptance, $pm);
        $this->assertSame(AcceptanceStatus::Submitted, $acceptance->status);

        // PM không tự duyệt được; Giám đốc duyệt.
        try {
            $workflow->approve($acceptance, $pm);
            $this->fail('PM không có quyền duyệt nghiệm thu.');
        } catch (AuthorizationException) {
            $this->assertSame(AcceptanceStatus::Submitted, $acceptance->refresh()->status);
        }

        $acceptance = $workflow->approve($acceptance, $director);
        $this->assertSame(AcceptanceStatus::Approved, $acceptance->status);
        $this->assertSame($director->getKey(), $acceptance->approved_by);

        // Kế toán lập hóa đơn từ biên bản: form điền sẵn khách hàng, hợp đồng, dự án, giá trị.
        $this->actingAs($this->accountant());

        Livewire::test(ViewAcceptance::class, ['record' => $acceptance->getRouteKey()])
            ->assertActionVisible('createInvoice');

        Livewire::withQueryParams(['acceptance' => $acceptance->getKey()])
            ->test(CreateInvoice::class)
            ->assertSchemaStateSet([
                'customer_id' => $project->customer_id,
                'contract_id' => $project->contract_id,
                'project_id' => $project->getKey(),
                'acceptance_id' => $acceptance->getKey(),
                'description' => 'Nghiệm thu đợt 1',
                'subtotal' => '40000000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::query()->first();

        $this->assertNotNull($invoice);
        $this->assertSame($acceptance->getKey(), $invoice->acceptance_id);
        $this->assertSame('40000000.00', $invoice->subtotal);
        $this->assertSame('44000000.00', $invoice->total);
        $this->assertSame($this->accountant()->getKey(), $invoice->created_by);
        $this->assertTrue($invoice->due_date->isSameDay(today()->addDays(30)));
    }

    public function test_rejected_acceptance_can_be_revised(): void
    {
        $pm = $this->createUserWithRole(SystemRole::ProjectManager);
        $director = $this->createUserWithRole(SystemRole::Director);
        $workflow = app(AcceptanceWorkflow::class);

        $acceptance = $workflow->submit(Acceptance::factory()->create(), $pm);
        $acceptance = $workflow->reject($acceptance, $director, 'Khách hàng chưa ký biên bản.');

        $this->assertSame(AcceptanceStatus::Rejected, $acceptance->status);
        $this->assertSame('Khách hàng chưa ký biên bản.', $acceptance->rejection_reason);
        $this->assertSame(AcceptanceStatus::Draft, $workflow->revise($acceptance, $pm)->status);

        // Biên bản 0 đồng không gửi duyệt được.
        $this->expectException(WorkflowException::class);

        $workflow->submit(Acceptance::factory()->create(['amount' => 0]), $pm);
    }

    public function test_invoice_form_only_accepts_contracts_of_the_selected_customer(): void
    {
        $contract = $this->makeActiveContract();
        $otherContract = $this->makeActiveContract();

        $this->actingAs($this->accountant());

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'customer_id' => $contract->customer_id,
                'contract_id' => $otherContract->getKey(),
                'subtotal' => 1000000,
                'tax_rate' => 10,
                'invoice_date' => today()->toDateString(),
                'due_date' => today()->subDay()->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['contract_id', 'due_date']);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_pages_and_buttons_follow_permissions_and_status(): void
    {
        $invoice = $this->makeInvoice();
        $acceptance = Acceptance::factory()->create();

        $this->actingAs($this->accountant());

        $this->get(InvoiceResource::getUrl('index'))->assertOk()->assertSee($invoice->code);
        $this->get(InvoiceResource::getUrl('create'))->assertOk();
        $this->get(InvoiceResource::getUrl('view', ['record' => $invoice]))->assertOk()->assertSee('11.000.000');
        $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertOk();
        $this->get(AcceptanceResource::getUrl('index'))->assertOk()->assertSee($acceptance->code);
        $this->get(AcceptanceResource::getUrl('view', ['record' => $acceptance]))->assertOk();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('issue')
            ->assertActionHidden('recordPayment')
            ->callAction('issue');

        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
        $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertForbidden();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('issue')
            ->assertActionVisible('recordPayment');

        // Sales xem được hóa đơn nhưng không phát hành, không thu tiền; nhân viên không vào được.
        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('recordPayment')
            ->assertActionHidden('cancel');

        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(InvoiceResource::getUrl('index'))
            ->assertForbidden();
    }
}
