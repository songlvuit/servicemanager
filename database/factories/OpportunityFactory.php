<?php

namespace Database\Factories;

use App\Enums\OpportunityStatus;
use App\Models\Customer;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => 'Cơ hội '.fake()->unique()->numberBetween(1, 99999),
            'estimated_value' => 100000000,
            'probability' => 50,
            'expected_close_date' => today()->addMonth(),
            'status' => OpportunityStatus::New,
        ];
    }
}
