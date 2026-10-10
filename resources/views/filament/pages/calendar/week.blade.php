@php
    /**
     * Lịch tuần: 7 ngày, mỗi ngày chia sáng / chiều – tối.
     *
     * @var array $data       Kết quả của CalendarQuery::week()
     * @var bool $clickable   Trong panel: bấm thẻ để mở chi tiết.
     */
    $clickable ??= false;
    $bands = ['morning' => 'Sáng', 'afternoon' => 'Chiều – tối'];
@endphp

<div class="sm-week-wrap">
    <div class="sm-week">
        @foreach ($data['days'] as $day)
            <section class="sm-day {{ $day['isToday'] ? 'is-today' : '' }} {{ $day['isWeekend'] ? 'is-weekend' : '' }}" aria-label="{{ $day['weekday'] }} {{ $day['date']->format('d/m') }}">
                <header class="sm-day-head">
                    <span>
                        <span class="sm-day-name">{{ $day['weekday'] }}</span>
                        <span class="sm-day-date">{{ $day['date']->format('d/m') }}</span>
                    </span>

                </header>

                <div class="sm-day-meta">
                    @foreach ($day['leave'] as $entry)
                        @include('filament.pages.calendar.chip', ['entry' => $entry, 'day' => $day['date'], 'showCrew' => true, 'clickable' => $clickable, 'conflictNames' => []])
                    @endforeach

                    {{-- Việc dài ngày: hiện gọn 3 việc đầu, phần còn lại bấm để mở. --}}
                    @php($long = $day['long'] ?? [])

                    @foreach (array_slice($long, 0, 3) as $entry)
                        @include('filament.pages.calendar.chip', ['entry' => $entry, 'day' => $day['date'], 'showCrew' => false, 'clickable' => $clickable, 'conflictNames' => []])
                    @endforeach

                    @if (count($long) > 3)
                        <details class="sm-more">
                            <summary>+{{ count($long) - 3 }} việc dài ngày</summary>

                            @foreach (array_slice($long, 3) as $entry)
                                @include('filament.pages.calendar.chip', ['entry' => $entry, 'day' => $day['date'], 'showCrew' => false, 'clickable' => $clickable, 'conflictNames' => []])
                            @endforeach
                        </details>
                    @endif

                    @foreach ($day['milestones'] as $milestone)
                        <div class="sm-chip is-milestone" title="{{ $milestone }}">
                            @svg('heroicon-m-flag')
                            <span class="sm-chip-text">{{ $milestone }}</span>
                        </div>
                    @endforeach
                </div>

                @foreach ($bands as $band => $bandLabel)
                    <div class="sm-band">
                        <div class="sm-band-name">
                            <span>{{ $bandLabel }}</span>
                        </div>

                        @forelse ($day[$band] as $entry)
                            @include('filament.pages.calendar.card', [
                                'entry' => $entry,
                                'day' => $day['date'],
                                'clickable' => $clickable,
                                'conflictNames' => $data['conflicts'][$entry->getKey()] ?? [],
                            ])
                        @empty
                            <div class="sm-band-empty">—</div>
                        @endforelse
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
</div>
