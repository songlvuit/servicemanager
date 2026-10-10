<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Công việc. Người không có quyền "Xem tất cả" chỉ xem và cập nhật được công
 * việc được giao cho mình hoặc mình có trong ê-kíp của lịch.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('tasks.view') && $this->sees($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.update') && $this->sees($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function sees(User $user, Task $task): bool
    {
        return $task->isVisibleTo($user);
    }
}
