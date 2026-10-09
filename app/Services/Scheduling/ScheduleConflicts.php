<?php

namespace App\Services\Scheduling;

use App\Models\ScheduleEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Phát hiện trùng lịch: một người được xếp vào hai lịch có thời gian chồng
 * nhau, hoặc được xếp việc vào lúc đang nghỉ. Lịch đã huỷ không giữ chỗ.
 *
 * Trùng lịch chỉ là CẢNH BÁO — hệ thống vẫn cho lưu, vì đôi khi người xếp
 * lịch cố ý (ví dụ một người chạy hai việc gần nhau).
 */
class ScheduleConflicts
{
    /**
     * Các lịch khác đang giữ chỗ của những người trong $userIds trong khoảng
     * [$start, $end).
     *
     * @param  list<int|string>  $userIds
     * @return list<array{user: User, entry: ScheduleEntry}>
     */
    public function find(array $userIds, CarbonInterface $start, CarbonInterface $end, ?int $ignoreEntryId = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === [] || $end->lte($start)) {
            return [];
        }

        $entries = ScheduleEntry::query()
            ->with(['type', 'crew' => fn ($query) => $query->whereIn('users.id', $userIds)])
            ->occupying()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreEntryId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->whereHas('crew', fn (Builder $query) => $query->whereIn('users.id', $userIds))
            ->orderBy('starts_at')
            ->get();

        $conflicts = [];

        foreach ($entries as $entry) {
            foreach ($entry->crew as $user) {
                $conflicts[] = ['user' => $user, 'entry' => $entry];
            }
        }

        return $conflicts;
    }

    /**
     * @return list<array{user: User, entry: ScheduleEntry}>
     */
    public function forEntry(ScheduleEntry $entry): array
    {
        if (! $entry->status->occupiesTime()) {
            return [];
        }

        return $this->find($entry->crew()->pluck('users.id')->all(), $entry->starts_at, $entry->ends_at, $entry->getKey());
    }

    /**
     * Mỗi dòng một câu: 'Cường: trùng "Show Hà Nội" (07/10 13:00 – 21:00)',
     * 'Sơn: đang nghỉ (10/10, cả ngày)'.
     *
     * @param  list<array{user: User, entry: ScheduleEntry}>  $conflicts
     * @return list<string>
     */
    public function describe(array $conflicts): array
    {
        return array_map(function (array $conflict): string {
            $entry = $conflict['entry'];
            $when = $entry->isMultiDay()
                ? $entry->timeLabel()
                : $entry->starts_at->format('d/m').($entry->is_all_day ? ', cả ngày' : ' · '.$entry->timeLabel());

            return $entry->isLeave()
                ? "{$conflict['user']->name}: đang nghỉ ({$when})"
                : "{$conflict['user']->name}: trùng \"{$entry->title}\" ({$when})";
        }, $conflicts);
    }

    /**
     * Trong một tập lịch đã nạp sẵn (kèm crew), tìm những lịch có người bị
     * trùng: [id lịch => tên những người bị trùng].
     *
     * @param  Collection<int, ScheduleEntry>  $entries
     * @return array<int, list<string>>
     */
    public function among(Collection $entries): array
    {
        $byUser = [];

        foreach ($entries as $entry) {
            if (! $entry->status->occupiesTime()) {
                continue;
            }

            foreach ($entry->crew as $user) {
                $byUser[$user->getKey()][] = $entry;
            }
        }

        $conflicted = [];

        foreach ($byUser as $userId => $userEntries) {
            $count = count($userEntries);
            $name = $userEntries[0]->crew->firstWhere('id', $userId)?->name;

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = [$userEntries[$i], $userEntries[$j]];

                    if ($a->starts_at->lt($b->ends_at) && $a->ends_at->gt($b->starts_at)) {
                        foreach ([$a, $b] as $entry) {
                            // Lịch nghỉ không phải là thứ "bị trùng": chỉ đánh dấu việc xếp đè lên nó.
                            if ($entry->isLeave()) {
                                continue;
                            }

                            $conflicted[$entry->getKey()][] = (string) $name;
                        }
                    }
                }
            }
        }

        return array_map(fn (array $names): array => array_values(array_unique(array_filter($names))), $conflicted);
    }
}
