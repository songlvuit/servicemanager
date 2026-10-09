<?php

namespace Database\Factories;

use App\Enums\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetItem>
 */
class BudgetItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'category' => BudgetCategory::Other,
            'planned_amount' => 10000000,
        ];
    }
}
