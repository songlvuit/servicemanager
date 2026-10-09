<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use BuildsInvoices;
    use BuildsProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_payments_update_the_invoice_until_it_is_fully_paid(): void
    {
        $invoice = $this->issuedInvoice(); // tổng 11.000.000

        $payment = app(PaymentService::class)->record(
            $invoice,
            $this->accountant(),
            4000000,
            PaymentMethod::BankTransfer,
            today()->subDay(),
            ' BC-0001 ',
            'Chuyển khoản đợt 1',
        );

        $this->assertSame($invoice->getKey(), $payment->invoice_id);
        $this->assertSame($invoice->customer_id, $payment->customer_id);
        $this->assertSame($this->accountant()->getKey(), $payment->created_by);
        $this->assertSame('BC-0001', $payment->reference);
        $this->assertTrue($payment->payment_date->isYesterday());

        $invoice->refresh();

        $this->assertSame('4000000.00', $invoice->paid_amount);
        $this->assertSame('7000000.00', $invoice->remaining_amount);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        $this->pay($invoice, '6999999.99');

        $this->assertSame('0.01', $invoice->refresh()->remaining_amount);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        // Còn lại đúng 0 → Đã thu đủ.
        $this->pay($invoice, '0.01');

        $invoice->refresh();

        $this->assertSame('11000000.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(3, $invoice->payments()->count());
    }

    public function test_payment_cannot_exceed_the_outstanding_amount_or_be_zero(): void
    {
        $invoice = $this->issuedInvoice();

        foreach (['11000000.01', 0, -500000] as $amount) {
            try {
                $this->pay($invoice, $amount);
                $this->fail("Không được ghi nhận thanh toán {$amount}.");
            } catch (WorkflowException) {
                $this->assertSame('0.00', $invoice->refresh()->paid_amount);
            }
        }

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_payments_are_only_accepted_for_open_invoices(): void
    {
        $draft = $this->makeInvoice();
        $paid = $this->issuedInvoice();
        $this->pay($paid, 11000000);

        foreach ([$draft, $paid] as $invoice) {
            try {
                $this->pay($invoice, 1000);
                $this->fail("Không được thu tiền cho hóa đơn {$invoice->status->value}.");
            } catch (WorkflowException $exception) {
                $this->assertStringContainsString('đã phát hành và còn phải thu', $exception->getMessage());
            }
        }

        // Người không có quyền ghi nhận thanh toán.
        $this->expectException(AuthorizationException::class);

        app(PaymentService::class)->record(
            $this->issuedInvoice(),
            $this->createUserWithRole(SystemRole::Sales),
            1000,
            PaymentMethod::Cash,
        );
    }

    /**
     * Rule 5: không thể xoá (hay sửa số tiền của) thanh toán đã ghi nhận.
     */
    public function test_recorded_payments_cannot_be_deleted_or_changed(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->pay($invoice, 3000000);

        try {
            $payment->delete();
            $this->fail('Rule 5: thanh toán đã ghi nhận không xoá được.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('Không thể xoá thanh toán', $exception->getMessage());
        }

        try {
            $payment->forceFill(['amount' => 1])->save();
            $this->fail('Số tiền của thanh toán đã ghi nhận không sửa được.');
        } catch (WorkflowException) {
            $this->assertSame('3000000.00', $payment->refresh()->amount);
        }

        // Ghi chú thì bổ sung được.
        $payment->update(['notes' => 'Đã đối chiếu sao kê.']);
        $this->assertSame('Đã đối chiếu sao kê.', $payment->refresh()->notes);

        $this->assertNotNull(Payment::query()->find($payment->getKey()));
        $this->assertSame('3000000.00', $invoice->refresh()->paid_amount);

        $superAdmin = $this->createUserWithRole(SystemRole::SuperAdmin);
        $this->actingAs($superAdmin);

        $this->assertFalse(PaymentResource::canDelete($payment));
        $this->assertFalse(PaymentResource::canEdit($payment));
        $this->assertFalse($this->accountant()->can('delete', $payment));
    }

    public function test_project_shows_what_was_invoiced_and_collected(): void
    {
        $project = $this->makeProject();
        $attributes = ['project_id' => $project->getKey(), 'contract_id' => $project->contract_id, 'customer_id' => $project->customer_id];

        $first = $this->issuedInvoice($attributes);                       // 11.000.000
        $second = $this->issuedInvoice([...$attributes, 'subtotal' => 20000000]); // 22.000.000
        $this->makeInvoice($attributes);                                   // nháp: không tính

        $this->pay($first, 11000000);
        $this->pay($second, 2000000);

        $project->refresh();

        $this->assertSame('33000000.00', $project->invoiced_amount);
        $this->assertSame('13000000.00', $project->collected_amount);
        $this->assertSame('20000000.00', $project->outstandingAmount());
    }

    public function test_accountant_records_a_payment_from_the_invoice_page(): void
    {
        $invoice = $this->issuedInvoice();

        $this->actingAs($this->accountant());

        // Số tiền điền sẵn là toàn bộ số còn phải thu.
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('recordPayment')
            ->assertSchemaStateSet(['amount' => '11000000'])
            ->callMountedAction();

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);

        // Thu quá số còn lại: báo lỗi, không ghi nhận.
        $second = $this->issuedInvoice();

        Livewire::test(ViewInvoice::class, ['record' => $second->getRouteKey()])
            ->callAction('recordPayment', data: [
                'amount' => 99000000,
                'payment_date' => today()->toDateString(),
                'payment_method' => PaymentMethod::Cash->value,
            ])
            ->assertNotified('Không ghi nhận được thanh toán');

        $this->assertSame('0.00', $second->refresh()->paid_amount);
    }

    public function test_payment_screen_lists_payments_and_records_new_ones(): void
    {
        $invoice = $this->issuedInvoice();
        $payment = $this->pay($invoice, 1000000);

        $this->actingAs($this->accountant());

        $this->get(PaymentResource::getUrl('index'))->assertOk()->assertSee($invoice->code);

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->callAction('recordPayment', data: [
                'invoice_id' => $invoice->getKey(),
                'amount' => 2500000,
                'payment_date' => today()->toDateString(),
                'payment_method' => PaymentMethod::BankTransfer->value,
                'reference' => 'BC-2',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('3500000.00', $invoice->refresh()->paid_amount);

        // Sales xem được danh sách nhưng không có nút ghi nhận.
        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        Livewire::test(ListPayments::class)->assertActionHidden('recordPayment');

        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(PaymentResource::getUrl('index'))
            ->assertForbidden();
    }
}
