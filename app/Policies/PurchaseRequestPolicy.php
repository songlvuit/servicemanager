<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;

/**
 * Quyền trên yêu cầu mua hàng (module "Mua hàng"). Duyệt: người đang giữ vai
 * trò của bước duyệt hiện tại (cấu hình ở Hệ thống → Quy trình duyệt), không
 * phải người yêu cầu.
 */
class PurchaseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.view');
    }

    public function view(User $user, PurchaseRequest $request): bool
    {
        return $user->can('purchases.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.create');
    }

    public function update(User $user, PurchaseRequest $request): bool
    {
        return $user->can('purchases.update') && $request->isEditable();
    }

    public function delete(User $user, PurchaseRequest $request): bool
    {
        return $user->can('purchases.delete') && $request->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function submit(User $user, PurchaseRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function revise(User $user, PurchaseRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function cancel(User $user, PurchaseRequest $request): bool
    {
        return $user->can('purchases.update') || $user->can('purchases.approve');
    }

    public function approve(User $user, PurchaseRequest $request): bool
    {
        return app(ApprovalService::class)->canAct($user, $request->currentApproval()->first());
    }

    public function reject(User $user, PurchaseRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    /**
     * Người yêu cầu (còn quyền tạo yêu cầu mua hàng) hoặc người có quyền Sửa.
     */
    private function manages(User $user, PurchaseRequest $request): bool
    {
        return $user->can('purchases.update')
            || ($request->isOwnedBy($user) && $user->can('purchases.create'));
    }
}
