<?php

namespace App\Policies;

use App\Models\CustomerContact;
use App\Models\User;

/**
 * Liên hệ là một phần của hồ sơ khách hàng: ai xem được khách hàng thì xem
 * được liên hệ, ai sửa được khách hàng thì thêm / sửa / xoá được liên hệ.
 */
class CustomerContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customers.update');
    }

    public function update(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.update');
    }

    public function delete(User $user, CustomerContact $contact): bool
    {
        return $user->can('customers.update');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('customers.update');
    }
}
