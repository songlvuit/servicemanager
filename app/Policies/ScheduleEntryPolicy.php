<?php

namespace App\Policies;

use App\Models\ScheduleEntry;
use App\Models\User;

/**
 * Lịch làm việc là lịch chung: mọi người dùng đang hoạt động đều XEM được.
 * Tạo / sửa / xoá cần quyền của module "Lịch làm việc". Riêng lịch nghỉ của
 * chính mình thì ai cũng tự đăng ký, sửa và huỷ được.
 */
class ScheduleEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ScheduleEntry $entry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('schedules.create');
    }

    public function update(User $user, ScheduleEntry $entry): bool
    {
        return $user->can('schedules.update') || $this->isOwnLeave($user, $entry);
    }

    public function delete(User $user, ScheduleEntry $entry): bool
    {
        return $user->can('schedules.delete') || $this->isOwnLeave($user, $entry);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Ghi giờ công cho cả ê-kíp từ một lịch: cần quyền sửa lịch và quyền ghi
     * giờ công cho người khác.
     */
    public function logTimesheets(User $user, ScheduleEntry $entry): bool
    {
        return $user->can('schedules.update')
            && $user->can('timesheets.create')
            && $user->can('timesheets.view_all');
    }

    /**
     * Lịch nghỉ do chính người này đăng ký cho riêng mình.
     */
    private function isOwnLeave(User $user, ScheduleEntry $entry): bool
    {
        if (! $entry->isLeave() || $entry->created_by !== $user->getKey()) {
            return false;
        }

        $crew = $entry->crew()->pluck('users.id')->all();

        return $crew === [$user->getKey()];
    }
}
