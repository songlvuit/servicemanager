<?php

namespace App\Console\Commands;

use App\Services\Billing\InvoiceWorkflow;
use Illuminate\Console\Command;

class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Chuyển các hóa đơn còn phải thu đã quá ngày đến hạn sang trạng thái Quá hạn';

    public function handle(InvoiceWorkflow $workflow): int
    {
        $marked = $workflow->markOverdue();

        $this->info("Đã chuyển {$marked} hóa đơn sang Quá hạn.");

        return self::SUCCESS;
    }
}
