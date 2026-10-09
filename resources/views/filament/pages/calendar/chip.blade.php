@php
    /**
     * Dải một dòng của một lịch (lưới tháng, theo người, dải nghỉ phép).
     *
     * @var \App\Models\ScheduleEntry $entry
     * @var \Carbon\CarbonInterface|null $day
     * @var bool $showCrew   Ghi tên người thay cho tên lịch (dải nghỉ phép trên lịch tuần).
     */
    $conflictNames ??= [];
    $clickable ??= false;
    $showCrew ??= false;
    $day ??= null;
    $leave = $entry->isLeave();
    $classes = 'sm-chip'
        .($leave ? ' is-leave' : '')
        .($entry->status === \App\Enums\ScheduleStatus::Cancelled ? ' is-cancelled' : '')
        .($conflictNames !== [] ? ' is-conflict' : '');
    $tag = $clickable ? 'button' : 'div';
    $text = $leave
        ? ($showCrew ? 'Nghỉ: '.($entry->crew->pluck('name')->implode(', ') ?: $entry->title) : 'Nghỉ')
        : $entry->title;
    $time = $entry->is_all_day || ($day && $entry->isMultiDay() && ! $entry->starts_at->isSameDay($day))
        ? null
        : $entry->starts_at->format('H:i');
    $tooltip = $entry->title.' · '.$entry->timeLabel()
        .(filled($entry->crewLabel()) ? ' · '.$entry->crewLabel() : '')
        .($conflictNames !== [] ? ' · TRÙNG LỊCH: '.implode(', ', $conflictNames) : '');
@endphp

<{{ $tag }}
    class="{{ $classes }}"
    style="--c: {{ $entry->type?->hex() ?? '#898781' }}"
    title="{{ $tooltip }}"
    @if ($clickable)
        type="button"
        wire:click="mountAction('viewEntry', { entry: {{ $entry->getKey() }} })"
    @endif
>
    @if ($conflictNames !== [])
        @svg('heroicon-m-exclamation-triangle')
    @elseif ($leave)
        @svg('heroicon-m-moon')
    @endif

    @if ($time !== null && ! $leave)
        <span class="sm-chip-time">{{ $time }}</span>
    @endif

    <span class="sm-chip-text">{{ $text }}</span>
</{{ $tag }}>
