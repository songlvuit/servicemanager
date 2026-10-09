<?php

namespace App\Console\Commands;

use App\Services\Quotations\QuotationWorkflow;
use Illuminate\Console\Command;

class ExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Chuyển các báo giá Đã duyệt / Đã gửi khách đã quá ngày hiệu lực sang Hết hạn';

    public function handle(QuotationWorkflow $workflow): int
    {
        $expired = $workflow->expireOverdue();

        $this->components->info("Đã chuyển {$expired} báo giá sang trạng thái Hết hạn.");

        return self::SUCCESS;
    }
}
