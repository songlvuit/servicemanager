<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\TableExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            TableExport::make('invoices.export', 'hoa-don', 'Danh sách hóa đơn', fn (): array => [
                'Số hóa đơn' => 'code',
                'Khách hàng' => 'customer.name',
                'Hợp đồng' => 'contract.code',
                'Nội dung' => 'description',
                'Ngày hóa đơn' => 'invoice_date',
                'Hạn thanh toán' => 'due_date',
                'Giá trị trước thuế' => 'subtotal',
                'Thuế suất (%)' => 'tax_rate',
                'Thuế GTGT' => 'tax',
                'Tổng thanh toán' => 'total',
                'Đã thu' => 'paid_amount',
                'Còn phải thu' => 'remaining_amount',
                'Trạng thái' => 'status',
            ]),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (InvoiceStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            InvoiceStatus::values($statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'draft' => Tab::make('Nháp')
                ->modifyQueryUsing($byStatus(InvoiceStatus::Draft)),
            'open' => Tab::make('Còn phải thu')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
            'overdue' => Tab::make('Quá hạn')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->pastDue()),
            'paid' => Tab::make('Đã thu đủ')
                ->modifyQueryUsing($byStatus(InvoiceStatus::Paid)),
            'cancelled' => Tab::make('Đã huỷ')
                ->modifyQueryUsing($byStatus(InvoiceStatus::Cancelled)),
        ];
    }
}
