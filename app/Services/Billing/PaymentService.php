<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Exceptions\WorkflowException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ghi nhận thanh toán của khách hàng (mục 31). Trong cùng một transaction:
 * tạo thanh toán, cộng dồn invoice.paid_amount, tính lại remaining_amount và
 * trạng thái hóa đơn (còn 0 đồng → "Đã thu đủ").
 */
class PaymentService
{
    public function __construct(private readonly InvoiceWorkflow $invoices) {}

    public function record(
        Invoice $invoice,
        User $by,
        int|float|string $amount,
        PaymentMethod $method,
        ?CarbonInterface $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
    ): Payment {
        return AuditLogger::transactionAs($by, function () use ($invoice, $by, $amount, $method, $paymentDate, $reference, $notes): Payment {
            // Khoá hóa đơn để hai người không cùng lúc thu quá số còn lại.
            $invoice = Invoice::query()->withTrashed()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($by->cannot('recordPayment', $invoice)) {
                throw new AuthorizationException('Bạn không có quyền ghi nhận thanh toán.');
            }

            if ($invoice->trashed() || ! $invoice->status->isOpen()) {
                throw new WorkflowException(
                    "Chỉ ghi nhận thanh toán cho hóa đơn đã phát hành và còn phải thu. {$invoice->code} đang ở trạng thái \"{$invoice->status->getLabel()}\"."
                );
            }

            $amount = Decimal::round($amount);

            if (! Decimal::isPositive($amount)) {
                throw new WorkflowException('Số tiền thanh toán phải lớn hơn 0.');
            }

            if (Decimal::compare($amount, $invoice->remaining_amount) > 0) {
                throw new WorkflowException(
                    'Số tiền thanh toán '.Money::format($amount).' lớn hơn số còn phải thu '.Money::format($invoice->remaining_amount).'.'
                );
            }

            $payment = new Payment([
                'payment_date' => $paymentDate ?? today(),
                'amount' => $amount,
                'payment_method' => $method,
                'reference' => filled($reference) ? trim((string) $reference) : null,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
            $payment->invoice_id = $invoice->getKey();
            $payment->customer_id = $invoice->customer_id;
            $payment->created_by = $by->getKey();
            $payment->save();

            // remaining_amount được tính lại khi lưu hóa đơn (total − paid_amount).
            $invoice->forceFill(['paid_amount' => Decimal::add($invoice->paid_amount, $amount)])->save();

            $this->invoices->refreshStatus($invoice);

            return $payment;
        });
    }
}
