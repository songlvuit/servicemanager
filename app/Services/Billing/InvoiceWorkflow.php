<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\WorkflowException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Quy trình hóa đơn (mục 30).
 *
 *   Nháp → Đã phát hành → Thu một phần → Đã thu đủ
 *                     ↘ Quá hạn (khi quá ngày đến hạn mà chưa thu đủ)
 *
 * Sau khi phát hành, trạng thái luôn được SUY RA từ số đã thu và ngày đến hạn
 * (statusFor) — không ai tự đặt "Đã thu đủ" được (Rule 3).
 */
class InvoiceWorkflow extends StatusWorkflow
{
    /**
     * Nháp → Đã phát hành (hoặc Quá hạn nếu ngày đến hạn đã qua).
     */
    public function issue(Invoice $invoice, User $by): Invoice
    {
        return AuditLogger::transactionAs($by, function () use ($invoice, $by): Invoice {
            $invoice = $this->lock($invoice);

            $this->authorize($by, 'issue', $invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                $this->failTransition($invoice, InvoiceStatus::Issued);
            }

            if (! Decimal::isPositive($invoice->total)) {
                throw new WorkflowException('Giá trị hóa đơn phải lớn hơn 0.');
            }

            $invoice->forceFill(['status' => InvoiceStatus::Issued, 'issued_at' => now()]);
            $invoice->forceFill(['status' => $this->statusFor($invoice)])->save();

            return $invoice;
        });
    }

    /**
     * Huỷ hóa đơn nháp hoặc hóa đơn đã phát hành nhưng chưa thu đồng nào.
     */
    public function cancel(Invoice $invoice, User $by, string $reason): Invoice
    {
        $reason = $this->requireText($reason, 'Vui lòng nhập lý do huỷ hóa đơn.');

        return $this->transition($invoice, $by, 'cancel', InvoiceStatus::Cancelled, function (Invoice $invoice) use ($reason): array {
            if (Decimal::isPositive($invoice->paid_amount)) {
                throw new WorkflowException("Hóa đơn {$invoice->code} đã có thanh toán nên không huỷ được.");
            }

            return ['cancel_reason' => $reason];
        });
    }

    /**
     * Rule 3: không thể đánh dấu hóa đơn "Đã thu đủ" khi còn phải thu.
     * Hóa đơn chỉ thành "Đã thu đủ" khi các thanh toán đã ghi nhận đủ tổng tiền.
     */
    public function markPaid(Invoice $invoice, User $by): Invoice
    {
        return AuditLogger::transactionAs($by, function () use ($invoice, $by): Invoice {
            $invoice = $this->lock($invoice);

            $this->authorize($by, 'recordPayment', $invoice);

            if (! $invoice->status->isOpen()) {
                $this->failTransition($invoice, InvoiceStatus::Paid);
            }

            if (Decimal::isPositive($invoice->remaining_amount)) {
                throw new WorkflowException(
                    "Hóa đơn {$invoice->code} còn phải thu nên chưa thể đánh dấu đã thu đủ. Hãy ghi nhận thanh toán cho số tiền còn lại."
                );
            }

            return $this->refreshStatus($invoice);
        });
    }

    /**
     * Đặt lại trạng thái của hóa đơn đang mở theo số đã thu và ngày đến hạn.
     * Gọi trong transaction đang giữ khoá của hóa đơn.
     */
    public function refreshStatus(Invoice $invoice): Invoice
    {
        if ($invoice->status->isOpen()) {
            $invoice->forceFill(['status' => $this->statusFor($invoice)])->save();
        }

        return $invoice;
    }

    /**
     * Chuyển các hóa đơn còn phải thu đã quá ngày đến hạn sang "Quá hạn".
     * Chạy hằng ngày bởi scheduler (lệnh invoices:mark-overdue).
     */
    public function markOverdue(): int
    {
        $marked = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_date', '<', today())
            ->orderBy('id')
            ->each(function (Invoice $invoice) use (&$marked): void {
                DB::transaction(function () use ($invoice, &$marked): void {
                    $invoice = $this->lock($invoice);

                    if ($invoice->status->isOpen() && $this->statusFor($invoice) === InvoiceStatus::Overdue && $invoice->status !== InvoiceStatus::Overdue) {
                        $invoice->forceFill(['status' => InvoiceStatus::Overdue])->save();
                        $marked++;
                    }
                });
            });

        return $marked;
    }

    /**
     * Trạng thái đúng của một hóa đơn đã phát hành.
     */
    private function statusFor(Invoice $invoice): InvoiceStatus
    {
        $remaining = Decimal::sub($invoice->total, $invoice->paid_amount);

        return match (true) {
            ! Decimal::isPositive($remaining) => InvoiceStatus::Paid,
            $invoice->due_date !== null && $invoice->due_date->lt(today()) => InvoiceStatus::Overdue,
            Decimal::isPositive($invoice->paid_amount) => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };
    }
}
