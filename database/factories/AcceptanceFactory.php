<?php

namespace Database\Factories;

use App\Models\Acceptance;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo biên bản nghiệm thu NHÁP trị giá 50.000.000.
 *
 * @extends Factory<Acceptance>
 */
class AcceptanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'description' => 'Nghiệm thu '.fake()->unique()->numberBetween(1, 99999),
            'acceptance_date' => today(),
            'amount' => 50000000,
        ];
    }
}
