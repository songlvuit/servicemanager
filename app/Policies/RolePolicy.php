<?php

namespace App\Policies;

use App\Enums\SystemRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Super Admin được bỏ qua policy này (Gate::before trong AppServiceProvider).
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.update')
            && $role->name !== SystemRole::SuperAdmin->value;
    }

    /**
     * Vai trò hệ thống không xoá được.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.delete') && ! SystemRole::isSystem($role->name);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
