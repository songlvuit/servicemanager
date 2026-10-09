<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Thanh toán đã ghi nhận thì không sửa, không xoá (Rule 5) — kể cả Super
 * Admin: model Payment tự chặn thao tác xoá.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payments.create');
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
