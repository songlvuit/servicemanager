<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'tax_code' => fake()->unique()->numerify('03########'),
            'company_type' => CustomerType::Company,
            'industry' => fake()->randomElement(['Bán lẻ', 'Giáo dục', 'Bất động sản', 'Thực phẩm & đồ uống', 'Công nghệ']),
            'address' => fake()->streetAddress(),
            'province' => fake()->randomElement(['TP. Hồ Chí Minh', 'Hà Nội', 'Đà Nẵng']),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('09########'),
            'status' => CustomerStatus::Active,
        ];
    }

    public function lead(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Lead,
        ]);
    }
}
