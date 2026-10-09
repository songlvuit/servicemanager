<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Services\Approvals\ApprovalService;

/**
 * Quyền trên đề nghị chi.
 *
 *  - Người không có quyền "Xem tất cả" chỉ thấy đề nghị của mình và của dự án mình quản lý.
 *  - Người đề nghị tự sửa / gửi duyệt / xoá bản nháp của mình; người có quyền Sửa làm được cho mọi bản nháp.
 *  - Duyệt: người đang giữ vai trò của bước duyệt hiện tại (cấu hình ở Hệ thống → Quy trình
 *    duyệt), không phải người đề nghị — xem ApprovalService.
 *
 * Ràng buộc theo trạng thái được kiểm tra lại trong ExpenseWorkflow và
 * ExpenseResource vì Super Admin được bỏ qua policy.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can('expenses.view') && $expense->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->isEditable() && $this->manages($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $expense->isEditable()
            && ($user->can('expenses.delete') || ($expense->isOwnedBy($user) && $user->can('expenses.create')));
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->can('expenses.delete');
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function submit(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    public function revise(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    public function approve(User $user, Expense $expense): bool
    {
        return app(ApprovalService::class)->canAct($user, $expense->currentApproval()->first());
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $this->approve($user, $expense);
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $user->can('expenses.pay');
    }

    public function complete(User $user, Expense $expense): bool
    {
        return $user->can('expenses.pay');
    }

    /**
     * Chứng từ (hóa đơn, biên nhận) thêm được cho tới khi hồ sơ hoàn tất.
     */
    public function manageAttachments(User $user, Expense $expense): bool
    {
        return ! $expense->trashed()
            && $expense->completed_at === null
            && ($this->manages($user, $expense) || $user->can('expenses.pay'));
    }

    /**
     * Người đề nghị (còn quyền tạo đề nghị chi) hoặc người có quyền Sửa.
     */
    private function manages(User $user, Expense $expense): bool
    {
        return $user->can('expenses.update')
            || ($expense->isOwnedBy($user) && $user->can('expenses.create'));
    }
}
