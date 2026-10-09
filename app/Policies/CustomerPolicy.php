<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Quyền trên module "customers". Super Admin được bỏ qua policy này
 * (Gate::before trong AppServiceProvider).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('customers.delete');
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('customers.delete');
    }

    /**
     * Không xoá vĩnh viễn: dữ liệu khách hàng gắn với báo giá, hợp đồng, hóa đơn.
     */
    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function manageAttachments(User $user, Customer $customer): bool
    {
        return $user->can('customers.update') && ! $customer->trashed();
    }
}
