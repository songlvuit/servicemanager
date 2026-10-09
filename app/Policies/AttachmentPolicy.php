<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Tài liệu đính kèm đi theo quyền của chứng từ chứa nó: xem được chứng từ thì
 * tải được file; thêm / xoá file cần quyền `manageAttachments` của chứng từ.
 */
class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return $attachment->attachable !== null && $user->can('view', $attachment->attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $attachment->attachable !== null && $user->can('manageAttachments', $attachment->attachable);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
