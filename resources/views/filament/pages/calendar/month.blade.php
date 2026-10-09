@php
    /** @var array $data Kết quả của CalendarQuery::month() */
    $limit = 4;
@endphp

<div class="sm-month-wrap">
    <div class="sm-month">
        @foreach (\App\Services\Scheduling\CalendarQuery::WEEKDAYS as $weekday)
            <div class="sm-month-head">{{ $weekday }}</div>
        @endforeach

        @foreach ($data['weeks'] as $week)
            @foreach ($week as $day)
                <div class="sm-month-cell {{ $day['inMonth'] ? '' : 'is-out' }} {{ $day['isToday'] ? 'is-today' : '' }}" wire:key="month-{{ $day['date']->format('Ymd') }}">
                    <button
                        type="button"
                        class="sm-month-num"
                        title="Xem tuần có ngày {{ $day['date']->format('d/m/Y') }}"
                        wire:click="openDay('{{ $day['date']->toDateString() }}')"
                    >{{ $day['date']->day }}</button>

                    @foreach (array_slice($day['entries'], 0, $limit) as $entry)
                        @include('filament.pages.calendar.chip', [
                            'entry' => $entry,
                            'day' => $day['date'],
                            'showCrew' => true,
                            'clickable' => true,
                            'conflictNames' => $data['conflicts'][$entry->getKey()] ?? [],
                        ])
                    @endforeach

                    @if (count($day['entries']) > $limit)
                        <button type="button" class="sm-month-more" wire:click="openDay('{{ $day['date']->toDateString() }}')">
                            + {{ count($day['entries']) - $limit }} lịch nữa
                        </button>
                    @endif
                </div>
            @endforeach
        @endforeach
    </div>
</div>
