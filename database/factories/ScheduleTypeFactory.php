<?php

namespace Database\Factories;

use App\Models\ScheduleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleType>
 */
class ScheduleTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Loại lịch '.fake()->unique()->numberBetween(1, 99999),
            'color' => 'blue',
        ];
    }

    public function leave(): static
    {
        return $this->state(fn (): array => ['is_leave' => true, 'color' => 'gray']);
    }
}
