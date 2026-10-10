<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;

/**
 * Chấm công. Người không có quyền "Xem tất cả" chỉ xem và sửa công của nhân sự của mình.
 * Công của công việc đã huỷ bị khoá.
 */
class TimesheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('timesheets.view');
    }

    public function view(User $user, Timesheet $timesheet): bool
    {
        return $user->can('timesheets.view') && $this->sees($user, $timesheet);
    }

    public function create(User $user): bool
    {
        return $user->can('timesheets.create');
    }

    public function update(User $user, Timesheet $timesheet): bool
    {
        return $user->can('timesheets.update')
            && $this->sees($user, $timesheet)
            // Công tự sinh theo phân công chỉ người quản lý công của mọi người chỉnh được.
            && (! $timesheet->is_auto || $user->can('timesheets.view_all'))
            && ! $timesheet->task?->isCancelled();
    }

    public function delete(User $user, Timesheet $timesheet): bool
    {
        // Dòng tự sinh không xoá (sẽ được sinh lại): sửa số buổi về 0 nếu không tính công.
        return $user->can('timesheets.delete')
            && $this->sees($user, $timesheet)
            && ! $timesheet->is_auto
            && ! $timesheet->task?->isCancelled();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function sees(User $user, Timesheet $timesheet): bool
    {
        return $user->can('timesheets.view_all') || $timesheet->isOwnedBy($user);
    }
}
