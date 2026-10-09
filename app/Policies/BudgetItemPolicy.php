<?php

namespace App\Policies;

use App\Models\BudgetItem;
use App\Models\User;

/**
 * Ngân sách dự án. Dự án đã hoàn thành / đã huỷ thì ngân sách bị khoá — điều
 * kiện này được kiểm tra lại ở BudgetItemResource vì Super Admin bỏ qua policy.
 */
class BudgetItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('budgets.view');
    }

    public function view(User $user, BudgetItem $item): bool
    {
        return $user->can('budgets.view');
    }

    public function create(User $user): bool
    {
        return $user->can('budgets.create');
    }

    public function update(User $user, BudgetItem $item): bool
    {
        return $user->can('budgets.update') && ! $item->project?->isClosed();
    }

    public function delete(User $user, BudgetItem $item): bool
    {
        return $user->can('budgets.delete') && ! $item->project?->isClosed();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
