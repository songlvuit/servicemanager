<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.update');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $user->can('suppliers.delete');
    }

    /**
     * Không xoá vĩnh viễn: nhà cung cấp gắn với chi phí và đơn mua hàng.
     */
    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
