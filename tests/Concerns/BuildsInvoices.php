<?php

namespace Tests\Concerns;

use App\Enums\PaymentMethod;
use App\Enums\SystemRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\PaymentService;

/**
 * Dựng nhanh hóa đơn và thanh toán cho các bài test (cần seedAccessControl()).
 */
trait BuildsInvoices
{
    private ?User $billingAccountant = null;

    protected function accountant(): User
    {
        return $this->billingAccountant ??= $this->createUserWithRole(SystemRole::Accountant);
    }

    /**
     * Hóa đơn nháp: mặc định 10.000.000 + 10% thuế = 11.000.000, hạn sau 30 ngày.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeInvoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create($attributes)->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function issuedInvoice(array $attributes = []): Invoice
    {
        return app(InvoiceWorkflow::class)->issue($this->makeInvoice($attributes), $this->accountant());
    }

    protected function pay(Invoice $invoice, int|float|string $amount): Payment
    {
        return app(PaymentService::class)->record($invoice, $this->accountant(), $amount, PaymentMethod::BankTransfer);
    }
}
