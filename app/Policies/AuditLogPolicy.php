<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Nhật ký thao tác chỉ để xem. Không ai được tạo, sửa hay xoá — kể cả Super
 * Admin: model AuditLog tự chặn việc sửa và xoá.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit_logs.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit_logs.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
