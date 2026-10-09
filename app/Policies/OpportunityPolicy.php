<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;

/**
 * Quyền trên module "opportunities". Super Admin được bỏ qua policy này
 * (Gate::before trong AppServiceProvider).
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('opportunities.view');
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $user->can('opportunities.view');
    }

    public function create(User $user): bool
    {
        return $user->can('opportunities.create');
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $user->can('opportunities.update');
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $user->can('opportunities.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('opportunities.delete');
    }
}
