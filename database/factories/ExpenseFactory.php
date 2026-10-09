<?php

namespace Database\Factories;

use App\Enums\BudgetCategory;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo đề nghị chi NHÁP 1.000.000 (không thuế) nhóm "Khác". Chuyển trạng thái
 * bằng ExpenseWorkflow — không gán trạng thái trực tiếp.
 *
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'employee_id' => User::factory(),
            'category' => BudgetCategory::Other,
            'description' => 'Khoản chi '.fake()->unique()->numberBetween(1, 99999),
            'amount' => 1000000,
            'tax' => 0,
            'expense_date' => today(),
        ];
    }
}
