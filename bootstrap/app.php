<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ứng dụng chạy sau Nginx (và Cloudflare ở production): tin cậy header
        // X-Forwarded-* để nhận đúng HTTPS và IP của người dùng. Dùng "*" vì
        // container app không mở cổng ra ngoài, chỉ nhận request từ Nginx nội bộ.
        $middleware->trustProxies(at: '*');

        // Route ngoài panel (tải tài liệu) đưa người chưa đăng nhập về trang đăng nhập của panel.
        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
