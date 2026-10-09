<?php

namespace App\Policies;

use App\Models\ApprovalRule;
use App\Models\User;

/**
 * Quy trình duyệt là cấu hình hệ thống: dùng quyền của module "Cấu hình".
 */
class ApprovalRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.view');
    }

    public function view(User $user, ApprovalRule $rule): bool
    {
        return $user->can('settings.view');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.update');
    }

    public function update(User $user, ApprovalRule $rule): bool
    {
        return $user->can('settings.update');
    }

    public function delete(User $user, ApprovalRule $rule): bool
    {
        return $user->can('settings.update');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
