<?php

namespace App\Console\Commands;

use App\Services\Notifications\ReminderService;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Gửi thông báo nhắc: hợp đồng sắp hết hạn, hóa đơn sắp tới hạn / quá hạn, dự án sắp tới hạn, lịch làm việc ngày mai (mỗi sự kiện một lần)';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->send();

        $this->info("Đã nhắc: {$sent['contracts']} hợp đồng sắp hết hạn, {$sent['invoices_due']} hóa đơn sắp tới hạn, "
            ."{$sent['invoices_overdue']} hóa đơn quá hạn, {$sent['projects']} dự án sắp tới hạn, {$sent['schedules']} lượt nhắc lịch ngày mai.");

        return self::SUCCESS;
    }
}
