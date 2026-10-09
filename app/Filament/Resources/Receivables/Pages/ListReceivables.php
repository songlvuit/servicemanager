<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Receivables\Widgets\ReceivableAgingOverview;
use App\Filament\Support\TableExport;
use App\Models\Invoice;
use App\Services\Billing\ReceivableAging;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Các hóa đơn đã phát hành còn phải thu, tính tới '.today()->format('d/m/Y').'.';
    }

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('receivables.export', 'cong-no', 'Công nợ phải thu', fn (): array => [
                'Số hóa đơn' => 'code',
                'Khách hàng' => 'customer.name',
                'Ngày hóa đơn' => 'invoice_date',
                'Hạn thanh toán' => 'due_date',
                'Số ngày quá hạn' => fn (Invoice $invoice): int => $invoice->daysOverdue(),
                'Nhóm tuổi nợ' => fn (Invoice $invoice): string => ReceivableAging::label(app(ReceivableAging::class)->bucketFor($invoice)),
                'Tổng thanh toán' => 'total',
                'Đã thu' => 'paid_amount',
                'Còn phải thu' => 'remaining_amount',
                'Trạng thái' => 'status',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ReceivableAgingOverview::class,
        ];
    }
}
