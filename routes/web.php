<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\PublicCalendarController;
use Illuminate\Support\Facades\Route;

// Toàn bộ giao diện nằm trong panel Filament tại /admin.
Route::redirect('/', '/admin');

// Tài liệu đính kèm: chỉ người đã đăng nhập và được xem chứng từ mới tải được.
Route::get('/admin/attachments/{attachment}', AttachmentController::class)
    ->middleware(['auth', 'throttle:120,1'])
    ->name('attachments.show');

// Lịch làm việc xem không cần đăng nhập: bảo vệ bằng mã bí mật trong đường dẫn
// (bật / đổi / tắt ở trang Lịch làm việc → Chia sẻ). Chỉ đọc.
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/lich/{token}', [PublicCalendarController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{32,64}')
        ->name('calendar.public');

    Route::get('/lich/{token}/lich.ics', [PublicCalendarController::class, 'feed'])
        ->where('token', '[A-Za-z0-9]{32,64}')
        ->name('calendar.public.feed');

    Route::get('/lich-cua-toi/{token}.ics', [PublicCalendarController::class, 'personal'])
        ->where('token', '[A-Za-z0-9]{32,64}')
        ->name('calendar.feed');
});
