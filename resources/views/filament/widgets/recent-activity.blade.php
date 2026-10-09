@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AuditLog> $logs */
@endphp

<x-filament-widgets::widget>
    <style>
        .sm-activity { display: flex; flex-direction: column; }
        .sm-activity-row { display: flex; gap: .75rem; padding: .625rem 0; border-bottom: 1px solid color-mix(in oklab, var(--gray-500) 14%, transparent); }
        .sm-activity-row:last-child { border-bottom: 0; padding-bottom: 0; }
        .sm-activity-row:first-child { padding-top: 0; }
        .sm-activity-main { flex: 1; min-width: 0; }
        .sm-activity-title { font-size: .875rem; line-height: 1.4; overflow-wrap: anywhere; }
        .sm-activity-title strong { font-weight: 600; }
        .sm-activity-note { margin-top: .125rem; font-size: .8125rem; opacity: .7; overflow-wrap: anywhere; }
        .sm-activity-time { flex: none; font-size: .75rem; opacity: .6; white-space: nowrap; padding-top: .125rem; }
        .sm-activity-foot { margin-top: .875rem; font-size: .8125rem; }
        .sm-activity-foot a { color: var(--primary-600); font-weight: 500; }
        .dark .sm-activity-foot a { color: var(--primary-400); }
        .sm-activity-foot a:hover { text-decoration: underline; }
    </style>

    <x-filament::section
        heading="Hoạt động gần đây"
        :description="$seesAll ? null : 'Các thao tác gần đây của bạn.'"
        icon="heroicon-o-clock"
    >
        @if ($logs->isEmpty())
            <x-filament::empty-state
                heading="Chưa có hoạt động nào"
                description="Các thao tác tạo, sửa, duyệt và thanh toán sẽ được ghi lại tại đây."
                icon="heroicon-o-clock"
                icon-color="gray"
                :contained="false"
                compact
            />
        @else
            <div class="sm-activity">
                @foreach ($logs as $log)
                    <div class="sm-activity-row" wire:key="activity-{{ $log->getKey() }}">
                        <div class="sm-activity-main">
                            <div class="sm-activity-title">
                                <strong>{{ $log->actorName() }}</strong>
                                <x-filament::badge size="sm" :color="$log->actionColor()" style="display: inline-flex; vertical-align: middle; margin: 0 .25rem;">
                                    {{ $log->actionLabel() }}
                                </x-filament::badge>
                                {{ $log->label }}
                            </div>

                            @if (filled($log->description))
                                <div class="sm-activity-note">{{ $log->description }}</div>
                            @endif
                        </div>

                        <time class="sm-activity-time" datetime="{{ $log->created_at?->toIso8601String() }}" title="{{ $log->created_at?->format('d/m/Y H:i') }}">
                            {{ $log->created_at?->diffForHumans(short: true) }}
                        </time>
                    </div>
                @endforeach
            </div>

            @if ($allUrl)
                <div class="sm-activity-foot">
                    <a href="{{ $allUrl }}">Xem toàn bộ nhật ký →</a>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
