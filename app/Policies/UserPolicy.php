<?php

namespace App\Policies;

use App\Models\User;

/**
 * Super Admin được bỏ qua policy này (Gate::before trong AppServiceProvider).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Chỉ Super Admin mới được sửa tài khoản Super Admin khác.
     */
    public function update(User $user, User $model): bool
    {
        return $user->can('users.update') && ! $model->isSuperAdmin();
    }

    /**
     * Không ai tự xoá chính mình; không xoá được tài khoản Super Admin.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('users.delete')
            && ! $user->is($model)
            && ! $model->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('users.delete');
    }
}
