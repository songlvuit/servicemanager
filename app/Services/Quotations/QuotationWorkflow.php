<?php

namespace App\Services\Quotations;

use App\Enums\CustomerStatus;
use App\Enums\OpportunityStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\QuotationWorkflowException;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Toàn bộ nghiệp vụ làm thay đổi trạng thái hoặc số tiền của báo giá.
 *
 * Mỗi thao tác chạy trong một transaction, khoá dòng báo giá, kiểm tra quyền
 * của người thực hiện (policy) và kiểm tra bước chuyển trạng thái có hợp lệ
 * không — giao diện chỉ gọi vào đây, không tự sửa trạng thái.
 */
class QuotationWorkflow
{
    public function __construct(
        private readonly QuotationCalculator $calculator,
        private readonly QuotationNotifier $notifier,
    ) {}

    /**
     * Tính lại các cột tổng hợp từ hạng mục. Chỉ áp dụng cho báo giá nháp.
     */
    public function recalculate(Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($quotation): Quotation {
            $quotation = $this->lock($quotation);

            if (! $quotation->isEditable()) {
                throw new QuotationWorkflowException(
                    "Báo giá {$quotation->code} không còn là bản nháp nên không thể tính lại."
                );
            }

            return $this->applyTotals($quotation);
        });
    }

    /**
     * Nháp → Chờ duyệt.
     */
    public function submit(Quotation $quotation, User $by): Quotation
    {
        $quotation = AuditLogger::transactionAs($by, function () use ($quotation, $by): Quotation {
            $quotation = $this->lock($quotation);

            $this->authorize($by, 'submit', $quotation);
            $this->guardTransition($quotation, QuotationStatus::PendingApproval);

            if ($quotation->items()->doesntExist()) {
                throw new QuotationWorkflowException('Báo giá chưa có hạng mục nào nên chưa thể gửi duyệt.');
            }

            $quotation = $this->applyTotals($quotation);

            if (! Decimal::isPositive($quotation->total)) {
                throw new QuotationWorkflowException('Tổng giá trị báo giá phải lớn hơn 0.');
            }

            $quotation->forceFill([
                'status' => QuotationStatus::PendingApproval,
                'requires_director_approval' => $quotation->exceedsDirectorDiscountThreshold(),
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'approval_note' => null,
                'rejection_reason' => null,
            ])->save();

            // Cơ hội gắn với báo giá chuyển sang bước "Đã gửi đề xuất".
            $opportunity = $quotation->opportunity;

            if ($opportunity !== null && in_array($opportunity->status, [OpportunityStatus::New, OpportunityStatus::Contacted], true)) {
                $opportunity->update(['status' => OpportunityStatus::Proposal]);
            }

            return $quotation;
        });

        $this->notifier->approvalRequested($quotation, $by);

        return $quotation;
    }

    /**
     * Chờ duyệt → Đã duyệt.
     */
    public function approve(Quotation $quotation, User $by, ?string $note = null): Quotation
    {
        $quotation = $this->transition($quotation, $by, 'approve', QuotationStatus::Approved, fn (Quotation $quotation): array => [
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
            'approval_note' => $this->clean($note),
            'rejection_reason' => null,
        ]);

        $this->notifier->approved($quotation);

        return $quotation;
    }

    /**
     * Chờ duyệt → Bị từ chối (người duyệt không đồng ý). Bắt buộc có lý do.
     */
    public function reject(Quotation $quotation, User $by, string $reason): Quotation
    {
        $reason = $this->requireReason($reason, 'Vui lòng nhập lý do từ chối.');

        $quotation = $this->transition($quotation, $by, 'reject', QuotationStatus::Rejected, fn (Quotation $quotation): array => [
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => $reason,
        ], from: QuotationStatus::PendingApproval);

        $this->notifier->rejected($quotation);

        return $quotation;
    }

    /**
     * Đã duyệt → Đã gửi khách.
     */
    public function markSent(Quotation $quotation, User $by): Quotation
    {
        return $this->transition($quotation, $by, 'markSent', QuotationStatus::Sent, fn (Quotation $quotation): array => [
            'sent_at' => now(),
        ]);
    }

    /**
     * Đã gửi khách → Khách chấp nhận. Cơ hội liên quan thành "Thắng", khách
     * hàng tiềm năng thành "Đang hoạt động".
     */
    public function markAccepted(Quotation $quotation, User $by): Quotation
    {
        return $this->transition($quotation, $by, 'markAccepted', QuotationStatus::Accepted, function (Quotation $quotation): array {
            $quotation->opportunity?->update(['status' => OpportunityStatus::Won]);

            $customer = $quotation->customer;

            if ($customer !== null && $customer->status === CustomerStatus::Lead) {
                $customer->update(['status' => CustomerStatus::Active]);
            }

            return ['accepted_at' => now()];
        });
    }

    /**
     * Đã gửi khách → Bị từ chối (khách hàng không đồng ý). Bắt buộc có lý do.
     */
    public function markDeclined(Quotation $quotation, User $by, string $reason): Quotation
    {
        $reason = $this->requireReason($reason, 'Vui lòng nhập lý do khách hàng từ chối.');

        return $this->transition($quotation, $by, 'markDeclined', QuotationStatus::Rejected, fn (Quotation $quotation): array => [
            'rejection_reason' => 'Khách hàng từ chối: '.$reason,
        ], from: QuotationStatus::Sent);
    }

    public function cancel(Quotation $quotation, User $by, ?string $reason = null): Quotation
    {
        return $this->transition($quotation, $by, 'cancel', QuotationStatus::Cancelled, function (Quotation $quotation) use ($reason): array {
            $reason = $this->clean($reason);

            return $reason === null ? [] : ['rejection_reason' => 'Huỷ: '.$reason];
        });
    }

    /**
     * Bị từ chối / Hết hạn → Nháp để chỉnh sửa và gửi duyệt lại.
     */
    public function revise(Quotation $quotation, User $by): Quotation
    {
        return $this->transition($quotation, $by, 'revise', QuotationStatus::Draft, fn (Quotation $quotation): array => [
            'requires_director_approval' => false,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'approval_note' => null,
            'sent_at' => null,
        ]);
    }

    /**
     * Chuyển các báo giá Đã duyệt / Đã gửi khách đã quá ngày hiệu lực sang
     * Hết hạn. Chạy hằng ngày bởi scheduler (lệnh quotations:expire).
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        Quotation::query()
            ->whereIn('status', [QuotationStatus::Approved->value, QuotationStatus::Sent->value])
            ->whereDate('valid_until', '<', today())
            ->orderBy('id')
            ->each(function (Quotation $quotation) use (&$expired): void {
                DB::transaction(function () use ($quotation, &$expired): void {
                    $quotation = $this->lock($quotation);

                    if ($quotation->status->canTransitionTo(QuotationStatus::Expired) && $quotation->isPastValidity()) {
                        $quotation->forceFill(['status' => QuotationStatus::Expired])->save();
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    /**
     * Khung chung của một bước chuyển: khoá, kiểm tra quyền, kiểm tra trạng
     * thái, ghi các cột do $changes trả về cùng trạng thái mới.
     *
     * @param  Closure(Quotation): array<string, mixed>  $changes
     */
    private function transition(
        Quotation $quotation,
        User $by,
        string $ability,
        QuotationStatus $target,
        Closure $changes,
        ?QuotationStatus $from = null,
    ): Quotation {
        return AuditLogger::transactionAs($by, function () use ($quotation, $by, $ability, $target, $changes, $from): Quotation {
            $quotation = $this->lock($quotation);

            $this->authorize($by, $ability, $quotation);

            if ($from !== null && $quotation->status !== $from) {
                $this->failTransition($quotation, $target);
            }

            $this->guardTransition($quotation, $target);

            $quotation->forceFill([...$changes($quotation), 'status' => $target])->save();

            return $quotation;
        });
    }

    private function applyTotals(Quotation $quotation): Quotation
    {
        $totals = $this->calculator->calculate($quotation->items()->get());

        $quotation->forceFill([
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'tax' => $totals->tax,
            'total' => $totals->total,
            'estimated_cost' => $totals->estimatedCost,
            'estimated_profit' => $totals->estimatedProfit,
            // Cột DECIMAL(8,2): chặn trường hợp doanh thu gần 0 làm tỷ lệ vượt giới hạn.
            'estimated_margin' => Decimal::clamp($totals->estimatedMargin, '-999999.99', '999999.99'),
        ])->save();

        return $quotation;
    }

    /**
     * Đọc lại báo giá và khoá dòng tới hết transaction, tránh hai người cùng
     * thao tác (ví dụ một người duyệt trong lúc người khác huỷ).
     */
    private function lock(Quotation $quotation): Quotation
    {
        return Quotation::query()
            ->withTrashed()
            ->whereKey($quotation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function authorize(User $by, string $ability, Quotation $quotation): void
    {
        if ($by->cannot($ability, $quotation)) {
            throw new AuthorizationException('Bạn không có quyền thực hiện thao tác này trên báo giá.');
        }
    }

    private function guardTransition(Quotation $quotation, QuotationStatus $target): void
    {
        if (! $quotation->status->canTransitionTo($target)) {
            $this->failTransition($quotation, $target);
        }
    }

    private function failTransition(Quotation $quotation, QuotationStatus $target): never
    {
        throw new QuotationWorkflowException(
            "Báo giá {$quotation->code} đang ở trạng thái \"{$quotation->status->getLabel()}\" nên không thể chuyển sang \"{$target->getLabel()}\"."
        );
    }

    private function requireReason(string $reason, string $message): string
    {
        $reason = $this->clean($reason);

        if ($reason === null) {
            throw new QuotationWorkflowException($message);
        }

        return $reason;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
