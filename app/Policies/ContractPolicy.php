<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

/**
 * Quyền trên hợp đồng. Ràng buộc theo trạng thái được kiểm tra lại trong
 * ContractWorkflow và ContractResource::canEdit()/canDelete() vì Super Admin
 * được bỏ qua policy.
 */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.create');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update') && $contract->isEditable();
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('contracts.delete') && $contract->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Contract $contract): bool
    {
        return $user->can('contracts.delete');
    }

    public function forceDelete(User $user, Contract $contract): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function sendForSignature(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update');
    }

    /**
     * Xác nhận hợp đồng đã ký và có hiệu lực: cần quyền duyệt.
     */
    public function activate(User $user, Contract $contract): bool
    {
        return $user->can('contracts.approve');
    }

    public function returnToDraft(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update');
    }

    public function complete(User $user, Contract $contract): bool
    {
        return $user->can('contracts.approve');
    }

    public function cancel(User $user, Contract $contract): bool
    {
        // Hợp đồng đã có hiệu lực thì chỉ người có quyền duyệt mới huỷ được.
        return $contract->isEditable()
            ? $user->can('contracts.update')
            : $user->can('contracts.approve');
    }

    public function manageAttachments(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update') && ! $contract->trashed();
    }
}
