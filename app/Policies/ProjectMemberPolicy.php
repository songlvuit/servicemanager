<?php

namespace App\Policies;

use App\Models\ProjectMember;
use App\Models\User;

/**
 * Thành viên dự án: ai xem được dự án thì xem được danh sách, ai sửa được dự
 * án thì thêm / bớt được thành viên.
 */
class ProjectMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, ProjectMember $member): bool
    {
        return $user->can('projects.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.update');
    }

    public function update(User $user, ProjectMember $member): bool
    {
        return $user->can('projects.update');
    }

    public function delete(User $user, ProjectMember $member): bool
    {
        return $user->can('projects.update');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
