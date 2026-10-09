{{-- Trang lỗi dùng chung (mục 69): không phụ thuộc CSS/JS của ứng dụng để luôn hiển thị được, kể cả khi ứng dụng đang gặp sự cố. --}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #4f46e5; --brand-text: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #020617; --card: #0f172a; --text: #f1f5f9; --muted: #94a3b8; --line: #1e293b; --brand: #6366f1; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: var(--bg); color: var(--text); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 30rem; padding: 2.5rem 2rem; text-align: center; background: var(--card); border: 1px solid var(--line); border-radius: 1rem; }
        .code { font-size: .875rem; font-weight: 600; letter-spacing: .08em; color: var(--brand); }
        h1 { margin: .5rem 0 .75rem; font-size: 1.5rem; line-height: 1.3; }
        p { margin: 0; color: var(--muted); line-height: 1.6; }
        .actions { margin-top: 1.75rem; display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }
        a { display: inline-block; padding: .625rem 1.125rem; border-radius: .5rem; font-size: .875rem; font-weight: 600; text-decoration: none; }
        a.primary { background: var(--brand); color: var(--brand-text); }
        a.secondary { color: var(--text); border: 1px solid var(--line); }
        a:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <div class="code">LỖI @yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="primary" href="{{ url('/admin') }}">Về trang chủ</a>
            <a class="secondary" href="javascript:history.back()">Quay lại</a>
        </div>
    </main>
</body>
</html>
