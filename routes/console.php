<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Lịch chạy tự động (Laravel Scheduler)
|--------------------------------------------------------------------------
|
| Container "scheduler" chạy `php artisan schedule:work` và thực hiện các
| tác vụ dưới đây theo giờ Việt Nam (APP_TIMEZONE).
|
*/

// Báo giá quá ngày hiệu lực → Hết hạn.
Schedule::command('quotations:expire')->dailyAt('00:10');

// Hóa đơn còn phải thu đã quá ngày đến hạn → Quá hạn.
Schedule::command('invoices:mark-overdue')->dailyAt('00:15');

// Nhắc hợp đồng sắp hết hạn, hóa đơn sắp tới hạn / quá hạn, dự án sắp tới hạn
// (chạy sau khi hóa đơn đã được chuyển sang Quá hạn).
Schedule::command('reminders:send')->dailyAt('07:30');

// Chạy lại cuối giờ chiều để nhắc cả những lịch ngày mai vừa được xếp trong ngày
// (lệnh không nhắc trùng: việc đã nhắc buổi sáng sẽ được bỏ qua).
Schedule::command('reminders:send')->dailyAt('17:00');

// Chỉ khi phát triển: chạy migration / seed / test theo yêu cầu đặt trong
// storage/app/dev-runner/request.json (xem App\Console\Commands\DevRunnerCommand).
if (app()->environment('local')) {
    Schedule::command('dev:runner')->everyMinute()->withoutOverlapping(45);
}
