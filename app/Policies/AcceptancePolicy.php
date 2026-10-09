<?php

namespace App\Policies;

use App\Models\Acceptance;
use App\Models\User;

/**
 * Quyền trên biên bản nghiệm thu. Chỉ bản nháp mới sửa / xoá được.
 */
class AcceptancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('acceptances.view');
    }

    public function view(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.view');
    }

    public function create(User $user): bool
    {
        return $user->can('acceptances.create');
    }

    public function update(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.update') && $acceptance->isEditable();
    }

    public function delete(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.delete') && $acceptance->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function submit(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.update');
    }

    public function revise(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.update');
    }

    public function approve(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.approve');
    }

    public function reject(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.approve');
    }

    public function manageAttachments(User $user, Acceptance $acceptance): bool
    {
        return $user->can('acceptances.update');
    }
}
