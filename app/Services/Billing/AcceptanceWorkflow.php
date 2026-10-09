<?php

namespace App\Services\Billing;

use App\Enums\AcceptanceStatus;
use App\Exceptions\WorkflowException;
use App\Models\Acceptance;
use App\Models\User;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Decimal;

/**
 * Quy trình nghiệm thu (mục 29): Nháp → Chờ duyệt → Đã nghiệm thu / Bị từ chối.
 * Biên bản đã nghiệm thu là căn cứ để lập hóa đơn.
 */
class AcceptanceWorkflow extends StatusWorkflow
{
    public function submit(Acceptance $acceptance, User $by): Acceptance
    {
        return $this->transition($acceptance, $by, 'submit', AcceptanceStatus::Submitted, function (Acceptance $acceptance): array {
            if (! Decimal::isPositive($acceptance->amount)) {
                throw new WorkflowException('Giá trị nghiệm thu phải lớn hơn 0.');
            }

            return [
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ];
        });
    }

    public function approve(Acceptance $acceptance, User $by): Acceptance
    {
        return $this->transition($acceptance, $by, 'approve', AcceptanceStatus::Approved, fn (): array => [
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
        ]);
    }

    public function reject(Acceptance $acceptance, User $by, string $reason): Acceptance
    {
        $reason = $this->requireText($reason, 'Vui lòng nhập lý do từ chối.');

        return $this->transition($acceptance, $by, 'reject', AcceptanceStatus::Rejected, fn (): array => [
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Bị từ chối → Nháp để chỉnh sửa và gửi duyệt lại.
     */
    public function revise(Acceptance $acceptance, User $by): Acceptance
    {
        return $this->transition($acceptance, $by, 'revise', AcceptanceStatus::Draft, fn (): array => [
            'submitted_at' => null,
        ]);
    }
}
