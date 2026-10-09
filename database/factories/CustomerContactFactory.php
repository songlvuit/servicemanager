<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContact>
 */
class CustomerContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->name(),
            'position' => fake()->randomElement(['Giám đốc', 'Trưởng phòng Marketing', 'Kế toán trưởng', 'Trợ lý']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09########'),
            'is_primary' => false,
        ];
    }
}
