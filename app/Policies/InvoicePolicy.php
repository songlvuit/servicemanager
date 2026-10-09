<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Quyền trên hóa đơn. Chỉ hóa đơn nháp mới sửa / xoá được (Rule 4) — điều
 * kiện này được kiểm tra lại ở InvoiceResource và trong model vì Super Admin
 * được bỏ qua policy.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.update') && $invoice->isEditable();
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.delete') && $invoice->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.delete');
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.update');
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.update');
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $user->can('payments.create');
    }

    public function manageAttachments(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.update') && ! $invoice->trashed();
    }
}
