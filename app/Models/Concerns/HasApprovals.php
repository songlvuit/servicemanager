<?php

namespace App\Models\Concerns;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Chứng từ chạy qua quy trình duyệt cấu hình được (đề nghị chi, yêu cầu mua hàng).
 */
trait HasApprovals
{
    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    /**
     * Lần gửi duyệt gần nhất.
     *
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function currentApproval(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany();
    }

    /**
     * Chứng từ đang chờ chính $user duyệt: bước hiện tại thuộc một vai trò
     * của $user và $user không phải người đề nghị. Super Admin thấy mọi
     * chứng từ đang chờ duyệt.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingApprovalBy(Builder $query, User $user): void
    {
        $query->whereHas('approvalRequests', function (Builder $query) use ($user): void {
            $query->where('status', ApprovalStatus::Pending->value);

            if (! $user->isSuperAdmin()) {
                $query
                    ->whereIn('current_role', $user->getRoleNames()->all())
                    ->where(fn (Builder $query) => $query
                        ->whereNull('requester_id')
                        ->orWhere('requester_id', '!=', $user->getKey()));
            }
        });
    }
}
