<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo dự án (Lập kế hoạch) thuộc một hợp đồng đang hiệu lực, doanh thu 100 triệu.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory()->active(),
            'name' => 'Dự án '.fake()->unique()->numberBetween(1, 99999),
            'contract_value' => 100000000,
            'start_date' => today(),
            'end_date' => today()->addMonths(2),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Active]);
    }
}
