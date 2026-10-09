<?php

namespace Database\Factories;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Dịch vụ '.fake()->unique()->numberBetween(1, 99999),
            'category' => 'Sản xuất nội dung',
            'default_unit' => 'gói',
            'default_price' => 10000000,
            'default_cost' => 6000000,
            'status' => ServiceStatus::Active,
        ];
    }
}
