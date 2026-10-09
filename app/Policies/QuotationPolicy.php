<?php

namespace App\Policies;

use App\Enums\QuotationStatus;
use App\Enums\SystemRole;
use App\Models\Quotation;
use App\Models\User;

/**
 * Quyền trên báo giá, gồm cả các bước của quy trình duyệt.
 *
 * Super Admin được bỏ qua policy này (Gate::before), nhưng KHÔNG bỏ qua được
 * các ràng buộc về trạng thái: chúng được kiểm tra lại trong
 * QuotationWorkflow và trong QuotationResource::canEdit()/canDelete().
 */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotations.create');
    }

    /**
     * Chỉ sửa được báo giá nháp.
     */
    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update') && $quotation->isEditable();
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.delete') && $quotation->status->isDeletable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.delete');
    }

    public function forceDelete(User $user, Quotation $quotation): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function submit(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update');
    }

    /**
     * Người có quyền duyệt; nếu chiết khấu vượt ngưỡng thì phải là Giám đốc.
     */
    public function approve(User $user, Quotation $quotation): bool
    {
        if (! $user->can('quotations.approve')) {
            return false;
        }

        if ($quotation->status !== QuotationStatus::PendingApproval) {
            return false;
        }

        return ! $quotation->requires_director_approval
            || $user->hasRole(SystemRole::Director->value);
    }

    public function reject(User $user, Quotation $quotation): bool
    {
        return $this->approve($user, $quotation);
    }

    public function markSent(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update');
    }

    public function markAccepted(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update');
    }

    public function markDeclined(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update');
    }

    /**
     * Người lập báo giá hoặc người duyệt đều có thể huỷ.
     */
    public function cancel(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update') || $user->can('quotations.approve');
    }

    public function revise(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update');
    }

    /**
     * Tài liệu kèm báo giá (bản đã ký, thư từ...) thêm được ở mọi trạng thái.
     */
    public function manageAttachments(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.update') && ! $quotation->trashed();
    }
}
