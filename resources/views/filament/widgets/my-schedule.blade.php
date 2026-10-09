@php
    /** @var list<array{date: \Carbon\CarbonImmutable, weekday: string, isToday: bool, entries: list<\App\Models\ScheduleEntry>}> $agenda */
@endphp

<x-filament-widgets::widget>
    @include('filament.pages.calendar.styles')

    <style>
        .sm-mysched { display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: .75rem 1rem; }
        .sm-mysched-day { min-width: 0; }
        .sm-mysched-date { margin-bottom: .375rem; font-weight: 600; }
        .sm-mysched-date span { font-weight: 400; color: var(--ink-3); font-variant-numeric: tabular-nums; }
        .sm-mysched-day.is-today .sm-mysched-date { color: var(--primary-600); }
        .dark .sm-mysched-day.is-today .sm-mysched-date { color: var(--primary-400); }
        .sm-mysched-list { display: flex; flex-direction: column; gap: .25rem; }
        .sm-mysched a.sm-chip { text-decoration: none; color: inherit; }
        .sm-mysched a.sm-chip:hover, .sm-mysched a.sm-chip:focus-visible { background: color-mix(in oklab, var(--c, var(--gray-500)) 24%, transparent); }
        .sm-mysched-foot { margin-top: .875rem; font-size: .8125rem; }
    </style>

    <x-filament::section
        heading="Lịch của tôi"
        description="{{ $days }} ngày tới"
        icon="heroicon-o-calendar-days"
    >
        <div class="sm-cal">
            @if ($agenda === [])
                <x-filament::empty-state
                    heading="Bạn chưa có lịch nào trong {{ $days }} ngày tới"
                    description="Khi được xếp vào một lịch, bạn sẽ nhận thông báo và thấy lịch tại đây."
                    icon="heroicon-o-calendar-days"
                    icon-color="gray"
                    :contained="false"
                    compact
                />
            @else
                <div class="sm-mysched">
                    @foreach ($agenda as $day)
                        <div class="sm-mysched-day {{ $day['isToday'] ? 'is-today' : '' }}">
                            <div class="sm-mysched-date">
                                {{ $day['isToday'] ? 'Hôm nay' : $day['weekday'] }}
                                <span>{{ $day['date']->format('d/m') }}</span>
                            </div>

                            <div class="sm-mysched-list">
                                @foreach ($day['entries'] as $entry)
                                    <a
                                        class="sm-chip {{ $entry->isLeave() ? 'is-leave' : '' }}"
                                        style="--c: {{ $entry->type?->hex() ?? '#898781' }}"
                                        href="{{ \App\Filament\Pages\WorkCalendar::entryUrl($entry) }}"
                                        title="{{ $entry->title }} · {{ $entry->timeLabel() }}{{ filled($entry->location) ? ' · '.$entry->location : '' }}"
                                    >
                                        @if ($entry->isLeave())
                                            @svg('heroicon-m-moon')
                                            <span class="sm-chip-text">Nghỉ</span>
                                        @else
                                            <span class="sm-chip-time">{{ $entry->is_all_day || ! $entry->starts_at->isSameDay($day['date']) ? 'Cả ngày' : $entry->starts_at->format('H:i') }}</span>
                                            <span class="sm-chip-text">{{ $entry->title }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="sm-mysched-foot">
                <a class="sm-cal-link" href="{{ $calendarUrl }}">Mở lịch chung của công ty →</a>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
