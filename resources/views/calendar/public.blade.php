{{-- Lịch tuần chỉ-xem, mở được mà không cần đăng nhập (xem PublicCalendarController). --}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    {{-- Màn hình treo ở văn phòng tự tải lại mỗi 5 phút. --}}
    <meta http-equiv="refresh" content="300">
    <title>Lịch làm việc {{ $week['label'] }} — {{ config('company.name', config('app.name')) }}</title>

    <style>
        /* Các biến màu cùng tên với Filament để dùng chung CSS của lịch. */
        :root {
            color-scheme: light dark;
            --gray-300: #cbd5e1; --gray-400: #94a3b8; --gray-500: #64748b; --gray-600: #475569; --gray-800: #1e293b; --gray-900: #0f172a;
            --primary-400: #818cf8; --primary-500: #6366f1; --primary-600: #4f46e5;
            --danger-400: #f87171; --danger-500: #ef4444; --danger-600: #dc2626;
            --page: #f8fafc; --text: #0f172a;
        }
        @media (prefers-color-scheme: dark) {
            :root { --page: #020617; --text: #f1f5f9; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 1.25rem; background: var(--page); color: var(--text); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.4; }
        button { font: inherit; color: inherit; background: none; border: 0; padding: 0; }
        svg { display: inline-block; }
        a { color: inherit; text-decoration: none; }
        header.page { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1.5rem; margin-bottom: 1rem; }
        header.page h1 { margin: 0; font-size: 1.25rem; }
        header.page p { margin: .125rem 0 0; font-size: .8125rem; color: var(--gray-500); }
        footer.page { margin-top: 1rem; font-size: .75rem; color: var(--gray-500); }
        @media (max-width: 860px) { body { padding: .75rem; } }
    </style>

    @include('filament.pages.calendar.styles')

    <script>
        // CSS của lịch dùng lớp .dark cho chế độ tối.
        const applyTheme = () => document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
        applyTheme();
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);
    </script>
</head>
<body>
    <div class="sm-cal">
        <header class="page">
            <div>
                <h1>Lịch làm việc</h1>
                <p>{{ config('company.name', config('app.name')) }} · chỉ xem</p>
            </div>

            <nav class="sm-cal-nav" aria-label="Chuyển tuần">
                <a class="sm-cal-btn" href="{{ $previousUrl }}" title="Tuần trước" aria-label="Tuần trước">@svg('heroicon-m-chevron-left')</a>
                <span class="sm-cal-label">{{ $week['label'] }}</span>
                <a class="sm-cal-btn" href="{{ $nextUrl }}" title="Tuần sau" aria-label="Tuần sau">@svg('heroicon-m-chevron-right')</a>
                @unless ($isCurrentWeek)
                    <a class="sm-cal-btn" href="{{ $todayUrl }}">Tuần này</a>
                @endunless
            </nav>
        </header>

        @include('filament.pages.calendar.week', ['data' => $week, 'clickable' => false, 'canCreate' => false])

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

        <footer class="page">Cập nhật lúc {{ now()->format('H:i d/m/Y') }} · trang tự tải lại mỗi 5 phút.</footer>
    </div>
</body>
</html>
