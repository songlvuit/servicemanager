@php
    /** @var \App\Filament\Pages\WorkCalendar $this */
@endphp

<x-filament-panels::page>
    @include('filament.pages.calendar.styles')

    <div class="sm-cal">
        <div class="sm-cal-bar">
            <div class="sm-cal-tabs" role="tablist" aria-label="Cách xem lịch">
                @foreach ($views as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $this->mode === $key ? 'true' : 'false' }}"
                        class="sm-cal-tab {{ $this->mode === $key ? 'is-active' : '' }}"
                        wire:click="setMode('{{ $key }}')"
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="sm-cal-nav">
                <button type="button" class="sm-cal-btn" wire:click="previous" title="Trước" aria-label="Trước">@svg('heroicon-m-chevron-left')</button>
                <span class="sm-cal-label">{{ $data['label'] }}</span>
                <button type="button" class="sm-cal-btn" wire:click="next" title="Sau" aria-label="Sau">@svg('heroicon-m-chevron-right')</button>
                <button type="button" class="sm-cal-btn" wire:click="goToToday">Hôm nay</button>
                <x-filament::loading-indicator wire:loading.delay class="h-5 w-5" style="margin-left: .25rem" />
            </div>

            @if ($this->mode !== 'mine')
                <div class="sm-cal-filters">
                    <select class="sm-cal-select" wire:model.live="type" aria-label="Lọc theo loại lịch">
                        <option value="">Mọi loại lịch</option>
                        @foreach ($types as $scheduleType)
                            <option value="{{ $scheduleType->getKey() }}">{{ $scheduleType->name }}</option>
                        @endforeach
                    </select>

                    <select class="sm-cal-select" wire:model.live="person" aria-label="Lọc theo người">
                        <option value="">Mọi người</option>
                        @foreach ($people as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>

                    @if ($projects !== [])
                        <select class="sm-cal-select" wire:model.live="project" aria-label="Lọc theo dự án">
                            <option value="">Mọi dự án</option>
                            @foreach ($projects as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <label class="sm-cal-check">
                        <input type="checkbox" wire:model.live="cancelled">
                        Hiện lịch đã huỷ
                    </label>

                    @if ($hasFilters)
                        <button type="button" class="sm-cal-link" wire:click="clearFilters">Bỏ lọc</button>
                    @endif
                </div>
            @endif
        </div>

        @switch($this->mode)
            @case('month')
                @include('filament.pages.calendar.month', ['data' => $data])
                @break
            @case('people')
                @include('filament.pages.calendar.people', ['data' => $data])
                @break
            @case('mine')
                @include('filament.pages.calendar.mine', ['data' => $data])
                @break
            @default
                @include('filament.pages.calendar.week', ['data' => $data, 'clickable' => true])
        @endswitch

        <div class="sm-legend" aria-label="Chú giải">
            @foreach ($types as $scheduleType)
                <span class="sm-legend-item" style="--c: {{ $scheduleType->hex() }}">
                    <span class="sm-dot"></span>
                    {{ $scheduleType->name }}
                </span>
            @endforeach

            <span class="sm-legend-item is-conflict">
                @svg('heroicon-m-exclamation-triangle')
                Viền đỏ: có người bị trùng lịch
            </span>
        </div>
    </div>
</x-filament-panels::page>
