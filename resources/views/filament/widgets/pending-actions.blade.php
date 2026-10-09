@php
    /** @var list<array{key: string, level: string, count: int, label: string, url: string}> $items */
    $icons = [
        'danger' => 'heroicon-m-exclamation-circle',
        'warning' => 'heroicon-m-clock',
        'success' => 'heroicon-m-check-circle',
    ];
@endphp

<x-filament-widgets::widget>
    {{-- CSS riêng của khối (không cần build theme); màu lấy từ biến màu của Filament nên chạy được cả dark mode. --}}
    <style>
        .sm-actions { display: flex; flex-direction: column; gap: .375rem; }
        .sm-action { display: flex; align-items: center; gap: .75rem; padding: .625rem .75rem; border-radius: .625rem; border: 1px solid color-mix(in oklab, var(--gray-500) 16%, transparent); transition: background-color .15s, border-color .15s; }
        .sm-action:hover, .sm-action:focus-visible { background: color-mix(in oklab, var(--gray-500) 8%, transparent); border-color: color-mix(in oklab, var(--gray-500) 30%, transparent); }
        .sm-action-icon { flex: none; width: 1.25rem; height: 1.25rem; }
        .sm-action-count { flex: none; min-width: 2rem; font-size: 1.125rem; font-weight: 700; font-variant-numeric: tabular-nums; text-align: right; }
        .sm-action-label { flex: 1; font-size: .875rem; }
        .sm-action-arrow { flex: none; width: 1rem; height: 1rem; opacity: .4; }
        .sm-action.is-danger .sm-action-icon { color: var(--danger-600); }
        .sm-action.is-warning .sm-action-icon { color: var(--warning-600); }
        .sm-action.is-success .sm-action-icon { color: var(--success-600); }
        .dark .sm-action.is-danger .sm-action-icon { color: var(--danger-400); }
        .dark .sm-action.is-warning .sm-action-icon { color: var(--warning-400); }
        .dark .sm-action.is-success .sm-action-icon { color: var(--success-400); }
    </style>

    <x-filament::section heading="Cần xử lý" icon="heroicon-o-inbox-stack">
        @if ($items === [])
            <x-filament::empty-state
                heading="Không có việc nào cần xử lý"
                description="Hóa đơn quá hạn, dự án vượt ngân sách và các chứng từ chờ bạn duyệt sẽ xuất hiện tại đây."
                icon="heroicon-o-check-circle"
                icon-color="success"
                :contained="false"
                compact
            />
        @else
            <div class="sm-actions">
                @foreach ($items as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="sm-action is-{{ $item['level'] }}"
                        wire:key="action-{{ $item['key'] }}"
                    >
                        <x-filament::icon :icon="$icons[$item['level']] ?? $icons['warning']" class="sm-action-icon" />
                        <span class="sm-action-count">{{ number_format($item['count'], 0, ',', '.') }}</span>
                        <span class="sm-action-label">{{ $item['label'] }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="sm-action-arrow" />
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
