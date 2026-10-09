<?php

namespace Tests\Concerns;

use App\Models\ScheduleEntry;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Dựng nhanh lịch làm việc cho các bài test.
 */
trait BuildsSchedules
{
    /**
     * Một lịch từ $from tới $to (chuỗi "Y-m-d H:i" hoặc Carbon) với ê-kíp cho trước.
     *
     * @param  list<User>  $crew
     * @param  array<string, mixed>  $attributes
     */
    protected function makeEntry(string|CarbonInterface $from, string|CarbonInterface $to, array $crew = [], array $attributes = []): ScheduleEntry
    {
        $entry = ScheduleEntry::factory()->create([
            'starts_at' => $from,
            'ends_at' => $to,
            ...$attributes,
        ]);

        $entry->crew()->sync(array_map(fn (User $user): int => $user->getKey(), $crew));

        return $entry->refresh()->load(['crew', 'type']);
    }
}
