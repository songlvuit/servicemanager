<?php

namespace App\Policies;

use App\Models\ScheduleType;
use App\Models\User;

class ScheduleTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('schedule_types.view');
    }

    public function view(User $user, ScheduleType $type): bool
    {
        return $user->can('schedule_types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('schedule_types.create');
    }

    public function update(User $user, ScheduleType $type): bool
    {
        return $user->can('schedule_types.update');
    }

    /**
     * Loại đã có lịch thì không xoá được (ngừng dùng thay vì xoá).
     */
    public function delete(User $user, ScheduleType $type): bool
    {
        return $user->can('schedule_types.delete') && ! $type->entries()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
