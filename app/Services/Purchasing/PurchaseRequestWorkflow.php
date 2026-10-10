<?php

namespace App\Services\Purchasing;

use App\Enums\ApprovalDocumentType;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\ApprovalRequest;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Approvals\ApprovalService;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use App\Support\Money;

/**
 * Quy trình yêu cầu mua hàng (mục 24):
 * Nháp → Chờ duyệt → Đã duyệt → Đã đặt hàng → Đã nhận hàng → Hoàn tất.
 * Ba bước cuối do đơn mua hàng lập từ yêu cầu này kéo theo
 * (xem PurchaseOrderWorkflow).
 */
class PurchaseRequestWorkflow extends StatusWorkflow
{
    private const ICON = 'heroicon-o-shopping-cart';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalNotifier $notifier,
    ) {}

    public function submit(PurchaseRequest $purchaseRequest, User $by): PurchaseRequest
    {
        $request = null;

        $purchaseRequest = $this->transition($purchaseRequest, $by, 'submit', PurchaseRequestStatus::Submitted, function (PurchaseRequest $purchaseRequest) use ($by, &$request): array {
            if (! Decimal::isPositive($purchaseRequest->amount)) {
                throw new WorkflowException('Giá trị yêu cầu mua hàng phải lớn hơn 0.');
            }

            if ($purchaseRequest->task?->isCancelled()) {
                throw new WorkflowException('Công việc đã huỷ nên không nhận thêm yêu cầu mua hàng.');
            }

            $request = $this->approvals->start(
                $purchaseRequest,
                ApprovalDocumentType::PurchaseRequest,
                $purchaseRequest->requester ?? $by,
                $purchaseRequest->amount,
            );

            return [
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ];
        }, from: [PurchaseRequestStatus::Draft]);

        $this->notifyApprovers($purchaseRequest, $request);

        return $purchaseRequest;
    }

    /**
     * Duyệt bước hiện tại; hết bước thì yêu cầu được duyệt.
     */
    public function approve(PurchaseRequest $purchaseRequest, User $by, ?string $comment = null): PurchaseRequest
    {
        [$purchaseRequest, $request] = AuditLogger::transactionAs($by, function () use ($purchaseRequest, $by, $comment): array {
            $purchaseRequest = $this->lock($purchaseRequest);

            $this->authorize($by, 'approve', $purchaseRequest);

            if ($purchaseRequest->status !== PurchaseRequestStatus::Submitted) {
                $this->failTransition($purchaseRequest, PurchaseRequestStatus::Approved);
            }

            $request = $this->approvals->approve($this->currentRequest($purchaseRequest), $by, $comment);

            if (! $request->isPending()) {
                $purchaseRequest->forceFill([
                    'status' => PurchaseRequestStatus::Approved,
                    'approved_by' => $by->getKey(),
                    'approved_at' => now(),
                ])->save();
            }

            return [$purchaseRequest, $request];
        });

        if ($request->isPending()) {
            $this->notifyApprovers($purchaseRequest, $request);
        } else {
            $this->notifier->result(
                $purchaseRequest->requester,
                'Yêu cầu mua hàng đã được duyệt',
                $this->summary($purchaseRequest),
                $this->url($purchaseRequest),
                self::ICON,
                success: true,
            );
        }

        return $purchaseRequest;
    }

    public function reject(PurchaseRequest $purchaseRequest, User $by, string $reason): PurchaseRequest
    {
        $reason = $this->requireText($reason, 'Vui lòng nhập lý do từ chối.');

        $purchaseRequest = $this->transition($purchaseRequest, $by, 'reject', PurchaseRequestStatus::Rejected, function (PurchaseRequest $purchaseRequest) use ($by, $reason): array {
            $this->approvals->reject($this->currentRequest($purchaseRequest), $by, $reason);

            return ['rejection_reason' => $reason];
        }, from: [PurchaseRequestStatus::Submitted]);

        $this->notifier->result(
            $purchaseRequest->requester,
            'Yêu cầu mua hàng bị từ chối',
            $this->summary($purchaseRequest).". Lý do: {$reason}",
            $this->url($purchaseRequest),
            self::ICON,
            success: false,
        );

        return $purchaseRequest;
    }

    /**
     * Bị từ chối → Nháp để chỉnh sửa và gửi duyệt lại.
     */
    public function revise(PurchaseRequest $purchaseRequest, User $by): PurchaseRequest
    {
        return $this->transition($purchaseRequest, $by, 'revise', PurchaseRequestStatus::Draft, fn (): array => [
            'submitted_at' => null,
        ]);
    }

    public function cancel(PurchaseRequest $purchaseRequest, User $by): PurchaseRequest
    {
        return $this->transition($purchaseRequest, $by, 'cancel', PurchaseRequestStatus::Cancelled, function (PurchaseRequest $purchaseRequest): array {
            $this->approvals->cancelPending($purchaseRequest);

            return [];
        });
    }

    private function currentRequest(PurchaseRequest $purchaseRequest): ApprovalRequest
    {
        return $purchaseRequest->currentApproval()->first()
            ?? throw new WorkflowException("{$purchaseRequest->code} chưa được gửi duyệt.");
    }

    private function notifyApprovers(PurchaseRequest $purchaseRequest, ?ApprovalRequest $request): void
    {
        if ($request === null) {
            return;
        }

        $this->notifier->awaiting(
            $request,
            'Yêu cầu mua hàng cần duyệt',
            $this->summary($purchaseRequest),
            $this->url($purchaseRequest),
            self::ICON,
        );
    }

    private function summary(PurchaseRequest $purchaseRequest): string
    {
        return "{$purchaseRequest->code} — ".str($purchaseRequest->description)->limit(80)
            .' ('.Money::format($purchaseRequest->amount).')';
    }

    private function url(PurchaseRequest $purchaseRequest): string
    {
        return PurchaseRequestResource::getUrl('view', ['record' => $purchaseRequest]);
    }
}
