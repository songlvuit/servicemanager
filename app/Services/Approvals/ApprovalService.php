<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalDocumentType;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use App\Exceptions\WorkflowException;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bộ máy duyệt dùng chung (mục 61). Quy trình không viết cứng trong code: các
 * bước sinh ra từ bảng approval_rules theo loại chứng từ và số tiền.
 *
 * Nguyên tắc:
 *  - Mỗi bước do một vai trò duyệt; ai có vai trò đó đều duyệt được.
 *  - Người đề nghị không tự duyệt chứng từ của mình. Bước thuộc vai trò mà
 *    chính người đề nghị đang giữ được bỏ qua, nhưng luôn còn ít nhất một
 *    bước phải do người khác duyệt.
 *  - Super Admin duyệt được mọi bước.
 */
class ApprovalService
{
    /**
     * Bắt đầu một lần duyệt mới cho chứng từ (các lần đang chờ trước đó bị huỷ).
     */
    public function start(Model $approvable, ApprovalDocumentType $type, User $requester, int|float|string $amount): ApprovalRequest
    {
        return DB::transaction(function () use ($approvable, $type, $requester, $amount): ApprovalRequest {
            $rules = ApprovalRule::query()->matching($type, $amount)->get();

            if ($rules->isEmpty()) {
                throw new WorkflowException(
                    "Chưa có quy trình duyệt nào áp dụng cho {$type->getLabel()} với số tiền này. "
                    .'Thiết lập tại Hệ thống → Quy trình duyệt.'
                );
            }

            $this->cancelPending($approvable);

            $request = new ApprovalRequest;
            $request->approvable()->associate($approvable);
            $request->forceFill([
                'document_type' => $type,
                'requester_id' => $requester->getKey(),
                'amount' => $amount,
                'status' => ApprovalStatus::Pending,
            ])->save();

            $requesterRoles = $requester->getRoleNames()->all();
            $hasPendingStep = false;

            foreach ($rules as $rule) {
                $skipped = in_array($rule->required_role, $requesterRoles, true);
                $hasPendingStep = $hasPendingStep || ! $skipped;

                $request->steps()->create([
                    'name' => $rule->name,
                    'approver_role' => $rule->required_role,
                    'sequence' => $rule->sequence,
                    'status' => $skipped ? ApprovalStepStatus::Skipped : ApprovalStepStatus::Pending,
                    'comment' => $skipped ? 'Bỏ qua: người đề nghị đang giữ vai trò này.' : null,
                ]);
            }

            // Mọi bước đều thuộc vai trò của người đề nghị: bước cuối vẫn phải do người khác duyệt.
            if (! $hasPendingStep) {
                $request->steps()->get()->last()?->forceFill([
                    'status' => ApprovalStepStatus::Pending,
                    'comment' => null,
                ])->save();
            }

            return $this->syncCurrentRole($request);
        });
    }

    public function canAct(User $user, ?ApprovalRequest $request): bool
    {
        if ($request === null || ! $request->isPending()) {
            return false;
        }

        $step = $request->pendingStep();

        if ($step === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $request->requester_id !== $user->getKey()
            && $user->hasRole($step->approver_role);
    }

    /**
     * Duyệt bước hiện tại. Hết bước chờ thì cả yêu cầu được duyệt.
     */
    public function approve(ApprovalRequest $request, User $by, ?string $comment = null): ApprovalRequest
    {
        return $this->act($request, $by, ApprovalStepStatus::Approved, $comment);
    }

    /**
     * Từ chối ở bước hiện tại: cả yêu cầu bị từ chối.
     */
    public function reject(ApprovalRequest $request, User $by, string $reason): ApprovalRequest
    {
        return $this->act($request, $by, ApprovalStepStatus::Rejected, $reason);
    }

    /**
     * Huỷ các lần duyệt còn đang chờ của chứng từ (khi chứng từ bị huỷ hoặc gửi duyệt lại).
     */
    public function cancelPending(Model $approvable): void
    {
        ApprovalRequest::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update([
                'status' => ApprovalStatus::Cancelled->value,
                'current_role' => null,
                'completed_at' => now(),
            ]);
    }

    private function act(ApprovalRequest $request, User $by, ApprovalStepStatus $result, ?string $comment): ApprovalRequest
    {
        return AuditLogger::transactionAs($by, function () use ($request, $by, $result, $comment): ApprovalRequest {
            $request = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->canAct($by, $request)) {
                throw new AuthorizationException('Bạn không phải người duyệt của bước hiện tại.');
            }

            $request->pendingStep()->forceFill([
                'status' => $result,
                'approver_user_id' => $by->getKey(),
                'approved_at' => now(),
                'comment' => filled($comment) ? trim((string) $comment) : null,
            ])->save();

            if ($result === ApprovalStepStatus::Rejected) {
                $request->forceFill(['status' => ApprovalStatus::Rejected, 'completed_at' => now()])->save();
            } elseif ($request->pendingStep() === null) {
                $request->forceFill(['status' => ApprovalStatus::Approved, 'completed_at' => now()])->save();
            }

            return $this->syncCurrentRole($request);
        });
    }

    private function syncCurrentRole(ApprovalRequest $request): ApprovalRequest
    {
        $request->forceFill([
            'current_role' => $request->isPending() ? $request->pendingStep()?->approver_role : null,
        ])->save();

        return $request;
    }
}
