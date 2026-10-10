<?php

namespace App\Services\Scheduling;

use App\Models\ScheduleEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Đọc lịch làm việc và sắp xếp thành các dạng hiển thị: tuần (sáng / chiều),
 * tháng, theo người, và danh sách theo ngày của một người.
 *
 * Bộ lọc: ['type' => id loại lịch, 'user' => id người, 'cancelled' => true để
 * hiện cả lịch đã huỷ].
 */
class CalendarQuery
{
    /** Trước giờ này là buổi sáng, từ giờ này trở đi là buổi chiều – tối. */
    public const AFTERNOON_FROM = 12;

    public const WEEKDAYS = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];

    public function __construct(private readonly ScheduleConflicts $conflicts) {}

    /**
     * Lịch có phần nằm trong [$from, $to], kèm loại, ê-kíp, khách hàng.
     *
     * @param  array{type?: mixed, user?: mixed, cancelled?: mixed}  $filters
     * @return Collection<int, ScheduleEntry>
     */
    public function entries(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        return ScheduleEntry::query()
            ->with(['type', 'crew', 'customer'])
            ->between($from, $to)
            ->when(empty($filters['cancelled']), fn (Builder $query) => $query->occupying())
            ->when($filters['type'] ?? null, fn (Builder $query, mixed $type) => $query->where('schedule_type_id', (int) $type))
            ->when($filters['user'] ?? null, fn (Builder $query, mixed $user) => $query->forUser((int) $user))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Bảy ngày của tuần chứa $date; mỗi ngày chia sáng / chiều.
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, conflicts: array<int, list<string>>, days: list<array{date: CarbonImmutable, weekday: string, isToday: bool, isWeekend: bool, morning: list<ScheduleEntry>, afternoon: list<ScheduleEntry>, leave: list<ScheduleEntry>, long: list<ScheduleEntry>}>}
     */
    public function week(CarbonInterface $date, array $filters = []): array
    {
        $from = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY);
        $to = $from->addDays(6)->endOfDay();

        $entries = $this->entries($from, $to, $filters);
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $onDay = $this->onDay($entries, $day);

            $days[] = [
                'date' => $day,
                'weekday' => self::WEEKDAYS[$day->dayOfWeekIso - 1],
                'isToday' => $day->isToday(),
                'isWeekend' => $day->isWeekend(),
                'leave' => $onDay->filter(fn (ScheduleEntry $entry): bool => $entry->isLeave())->values()->all(),
                // Việc dài ngày hiện gọn ở đầu ngày, không chiếm buổi sáng / chiều.
                'long' => $onDay->filter(fn (ScheduleEntry $entry): bool => $entry->isBackground())->values()->all(),
                'morning' => $onDay->filter(fn (ScheduleEntry $entry): bool => $this->inBand($entry, $day, 'morning'))->values()->all(),
                'afternoon' => $onDay->filter(fn (ScheduleEntry $entry): bool => $this->inBand($entry, $day, 'afternoon'))->values()->all(),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => $from->format('d/m').' – '.$to->format('d/m/Y'),
            'conflicts' => $this->conflicts->among($entries),
            'days' => $days,
        ];
    }

    /**
     * Lưới tháng (các tuần trọn vẹn, bắt đầu từ thứ Hai) của tháng chứa $date.
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, conflicts: array<int, list<string>>, weeks: list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, entries: list<ScheduleEntry>}>>}
     */
    public function month(CarbonInterface $date, array $filters = []): array
    {
        $month = CarbonImmutable::parse($date)->startOfMonth();
        $from = $month->startOfWeek(CarbonInterface::MONDAY);
        $to = $month->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY);

        $entries = $this->entries($from, $to, $filters);
        $weeks = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $weeks[intdiv((int) $from->diffInDays($day), 7)][] = [
                'date' => $day,
                'inMonth' => $day->month === $month->month,
                'isToday' => $day->isToday(),
                'entries' => $this->onDay($entries, $day)->values()->all(),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => 'Tháng '.$month->format('n/Y'),
            'conflicts' => $this->conflicts->among($entries),
            'weeks' => array_values($weeks),
        ];
    }

    /**
     * Tuần chứa $date, mỗi người một hàng: ai làm gì ngày nào, ai còn trống.
     * Hiện mọi người dùng đang hoạt động (hoặc chỉ người được lọc).
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, conflicts: array<int, list<string>>, days: list<array{date: CarbonImmutable, weekday: string, isToday: bool}>, rows: list<array{user: User, total: int, busy: bool, cells: array<string, list<ScheduleEntry>>}>}
     */
    public function people(CarbonInterface $date, array $filters = []): array
    {
        $from = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY);
        $to = $from->addDays(6)->endOfDay();

        $entries = $this->entries($from, $to, $filters);
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = [
                'date' => $day,
                'weekday' => self::WEEKDAYS[$day->dayOfWeekIso - 1],
                'isToday' => $day->isToday(),
            ];
        }

        $users = User::query()
            ->where('is_active', true)
            ->when($filters['user'] ?? null, fn (Builder $query, mixed $user) => $query->whereKey((int) $user))
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($users as $user) {
            $mine = $entries->filter(fn (ScheduleEntry $entry): bool => $entry->crew->contains('id', $user->getKey()));
            $cells = [];

            foreach ($days as $day) {
                $cells[$day['date']->toDateString()] = $this->onDay($mine, $day['date'])->values()->all();
            }

            $rows[] = [
                'user' => $user,
                'total' => $mine->reject(fn (ScheduleEntry $entry): bool => $entry->isLeave())->count(),
                'busy' => $mine->isNotEmpty(),
                'cells' => $cells,
            ];
        }

        // Người có lịch trong tuần xếp trước (vẫn theo tên), người chưa có lịch xếp sau.
        usort($rows, fn (array $a, array $b): int => ($a['busy'] ? 0 : 1) <=> ($b['busy'] ? 0 : 1));

        return [
            'from' => $from,
            'to' => $to,
            'label' => $from->format('d/m').' – '.$to->format('d/m/Y'),
            'conflicts' => $this->conflicts->among($entries),
            'days' => $days,
            'rows' => $rows,
        ];
    }

    /**
     * Lịch của một người trong $days ngày kể từ $from, gom theo ngày (bỏ ngày trống).
     *
     * @return list<array{date: CarbonImmutable, weekday: string, isToday: bool, entries: list<ScheduleEntry>}>
     */
    public function agenda(User $user, CarbonInterface $from, int $days = 30): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = $from->addDays(max(1, $days) - 1)->endOfDay();

        $entries = $this->entries($from, $to, ['user' => $user->getKey()]);
        $agenda = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $onDay = $this->onDay($entries, $day);

            if ($onDay->isEmpty()) {
                continue;
            }

            $agenda[] = [
                'date' => $day,
                'weekday' => self::WEEKDAYS[$day->dayOfWeekIso - 1],
                'isToday' => $day->isToday(),
                'entries' => $onDay->values()->all(),
            ];
        }

        return $agenda;
    }

    /**
     * Các lịch có phần nằm trong ngày $day, theo giờ bắt đầu trong ngày đó.
     *
     * @param  Collection<int, ScheduleEntry>  $entries
     * @return Collection<int, ScheduleEntry>
     */
    public function onDay(Collection $entries, CarbonInterface $day): Collection
    {
        $start = CarbonImmutable::parse($day)->startOfDay();
        $end = $start->endOfDay();

        return $entries
            ->filter(fn (ScheduleEntry $entry): bool => $entry->starts_at->lte($end) && $entry->ends_at->gte($start))
            ->sortBy(fn (ScheduleEntry $entry): int => $entry->starts_at->lt($start) ? 0 : $entry->starts_at->hour * 60 + $entry->starts_at->minute)
            ->values();
    }

    private function inBand(ScheduleEntry $entry, CarbonInterface $day, string $band): bool
    {
        return ! $entry->isLeave() && ! $entry->isBackground() && $this->band($entry, $day) === $band;
    }

    /**
     * Buổi của lịch trong ngày $day: lịch bắt đầu từ trước ngày đó, hoặc lịch
     * cả ngày, được xếp vào buổi sáng.
     */
    public function band(ScheduleEntry $entry, CarbonInterface $day): string
    {
        if ($entry->is_all_day || $entry->starts_at->lt(CarbonImmutable::parse($day)->startOfDay())) {
            return 'morning';
        }

        return $entry->starts_at->hour < self::AFTERNOON_FROM ? 'morning' : 'afternoon';
    }
}
