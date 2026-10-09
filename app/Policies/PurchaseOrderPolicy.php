<?php

namespace App\Policies;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Quyền trên đơn mua hàng (module "Mua hàng").
 *
 * Đặt hàng: đơn lập từ một yêu cầu mua hàng ĐÃ DUYỆT thì người có quyền Sửa
 * đặt được; đơn lập trực tiếp (không qua yêu cầu) cần quyền Duyệt.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.view');
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.create');
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.update') && $order->isEditable();
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.delete') && $order->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function order(User $user, PurchaseOrder $order): bool
    {
        $fromApprovedRequest = $order->purchaseRequest?->status === PurchaseRequestStatus::Approved;

        return $fromApprovedRequest
            ? $user->can('purchases.update')
            : $user->can('purchases.approve');
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.update');
    }

    public function complete(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.update');
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchases.update') || $user->can('purchases.approve');
    }
}
