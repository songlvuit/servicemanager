<?php

namespace Database\Factories;

use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleEntry>
 */
class ScheduleEntryFactory extends Factory
{
    /**
     * Mặc định: một lịch đã chốt hôm nay, 08:00 – 12:00, chưa có ê-kíp.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Lịch '.fake()->unique()->numberBetween(1, 99999),
            'schedule_type_id' => fn (): int => (ScheduleType::query()->where('is_leave', false)->orderBy('sort_order')->first()
                ?? ScheduleType::factory()->create())->getKey(),
            'starts_at' => today()->setTime(8, 0),
            'ends_at' => today()->setTime(12, 0),
        ];
    }

    public function leave(): static
    {
        return $this->state(fn (): array => [
            'title' => 'Nghỉ phép',
            'schedule_type_id' => (ScheduleType::query()->where('is_leave', true)->first() ?? ScheduleType::factory()->leave()->create())->getKey(),
            'is_all_day' => true,
        ]);
    }
}
