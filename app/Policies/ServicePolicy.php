<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Quyền trên module "services". Super Admin được bỏ qua policy này
 * (Gate::before trong AppServiceProvider).
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.view');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('services.view');
    }

    public function create(User $user): bool
    {
        return $user->can('services.create');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('services.update');
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->can('services.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('services.delete');
    }
}
