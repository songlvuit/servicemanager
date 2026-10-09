@php
    /**
     * Thẻ của một lịch.
     *
     * @var \App\Models\ScheduleEntry $entry
     * @var \Carbon\CarbonInterface|null $day      Ngày đang hiển thị (để ghi giờ riêng của ngày đó với lịch nhiều ngày).
     * @var list<string> $conflictNames            Tên những người bị trùng lịch trong thẻ này.
     * @var bool $clickable                        Trong panel: bấm để mở chi tiết.
     */
    $conflictNames ??= [];
    $clickable ??= false;
    $day ??= null;
    $status = $entry->status;
    $classes = 'sm-entry'
        .($status === \App\Enums\ScheduleStatus::Cancelled ? ' is-cancelled' : '')
        .($status === \App\Enums\ScheduleStatus::Tentative ? ' is-tentative' : '')
        .($conflictNames !== [] ? ' is-conflict' : '');
    $tag = $clickable ? 'button' : 'div';
    $place = collect([$entry->customer?->name, $entry->location])->filter()->implode(' · ');
@endphp

<{{ $tag }}
    class="{{ $classes }}"
    style="--c: {{ $entry->type?->hex() ?? '#898781' }}"
    @if ($clickable)
        type="button"
        wire:click="mountAction('viewEntry', { entry: {{ $entry->getKey() }} })"
        wire:key="entry-{{ $entry->getKey() }}-{{ $day?->format('Ymd') ?? 'x' }}"
    @endif
>
    <span class="sm-entry-top">
        <span class="sm-entry-type">
            <span class="sm-dot"></span>
            <span>{{ $entry->type?->name ?? 'Lịch' }}</span>
        </span>

        @if ($status !== \App\Enums\ScheduleStatus::Confirmed)
            <span class="sm-flag is-muted">{{ $status->getLabel() }}</span>
        @endif
    </span>

    <span class="sm-entry-title">{{ $entry->title }}</span>

    <span class="sm-entry-line is-time">
        @svg('heroicon-m-clock')
        <span>{{ $day ? $entry->timeLabelOn($day) : $entry->timeLabel() }}@if ($day && $entry->isMultiDay()) <span style="font-weight: 400; opacity: .75">· {{ $entry->starts_at->format('d/m') }}–{{ $entry->ends_at->format('d/m') }}</span>@endif</span>
    </span>

    @if (filled($entry->crewLabel()))
        <span class="sm-entry-line">
            @svg('heroicon-m-user-group')
            <span>{{ $entry->crewLabel() }}</span>
        </span>
    @endif

    @if ($place !== '')
        <span class="sm-entry-line">
            @svg('heroicon-m-map-pin')
            <span>{{ $place }}</span>
        </span>
    @endif

    @if (filled($entry->equipment))
        <span class="sm-entry-line is-note">
            <span>{{ $entry->equipment }}</span>
        </span>
    @endif

    @if ($conflictNames !== [])
        <span class="sm-entry-line is-conflict">
            @svg('heroicon-m-exclamation-triangle')
            <span>Trùng lịch: {{ implode(', ', $conflictNames) }}</span>
        </span>
    @endif
</{{ $tag }}>
