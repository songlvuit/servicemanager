<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Quyền trên dự án. Người không có quyền "Xem tất cả" chỉ xem được dự án
 * mình quản lý, là thành viên hoặc có công việc được giao.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view') && $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.update') && $project->isVisibleTo($user);
    }

    /**
     * Chỉ xoá được dự án còn ở bước lập kế hoạch (chưa phát sinh chi phí).
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.delete') && $project->isDeletable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function start(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function hold(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function resume(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function complete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function reopen(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function cancel(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function manageAttachments(User $user, Project $project): bool
    {
        return $this->update($user, $project) && ! $project->trashed();
    }
}
