@php
    /** @var array $data ['agenda' => CalendarQuery::agenda(), 'conflicts' => ...] */
@endphp

@if ($data['agenda'] === [])
    <div class="sm-empty">
        <strong>Bạn chưa có lịch nào trong 30 ngày này.</strong>
        Khi được xếp vào một lịch, bạn sẽ nhận thông báo và lịch hiện tại đây.
    </div>
@else
    <div class="sm-agenda">
        @foreach ($data['agenda'] as $day)
            <div class="sm-agenda-day {{ $day['isToday'] ? 'is-today' : '' }}" wire:key="agenda-{{ $day['date']->format('Ymd') }}">
                <div class="sm-agenda-date">
                    <strong>{{ $day['isToday'] ? 'Hôm nay' : $day['weekday'] }}</strong>
                    <span>{{ $day['date']->format('d/m/Y') }}</span>
                </div>

                <div class="sm-agenda-list">
                    @foreach ($day['entries'] as $entry)
                        @if ($entry->isLeave())
                            @include('filament.pages.calendar.chip', ['entry' => $entry, 'day' => $day['date'], 'clickable' => true, 'conflictNames' => []])
                        @else
                            @include('filament.pages.calendar.card', [
                                'entry' => $entry,
                                'day' => $day['date'],
                                'clickable' => true,
                                'conflictNames' => in_array(auth()->user()->name, $data['conflicts'][$entry->getKey()] ?? [], true) ? [auth()->user()->name] : [],
                            ])
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
