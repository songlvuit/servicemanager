<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Access;
use App\Filament\Support\TableExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Giá vốn và lợi nhuận ước tính chỉ xuất với người được xem chi phí nội bộ.
            TableExport::make('quotations.export', 'bao-gia', 'Danh sách báo giá', fn (): array => [
                'Số báo giá' => 'code',
                'Tiêu đề' => 'title',
                'Khách hàng' => 'customer.name',
                'Ngày báo giá' => 'quotation_date',
                'Hiệu lực đến' => 'valid_until',
                'Thành tiền trước thuế' => 'subtotal',
                'Chiết khấu' => 'discount',
                'Thuế' => 'tax',
                'Tổng cộng' => 'total',
                ...(Access::canSeeCosts() ? [
                    'Giá vốn ước tính' => 'estimated_cost',
                    'Lợi nhuận ước tính' => 'estimated_profit',
                    'Biên lợi nhuận ước tính (%)' => 'estimated_margin',
                ] : []),
                'Trạng thái' => 'status',
                'Người lập' => 'creator.name',
            ]),
        ];
    }

    /**
     * Lọc nhanh theo các nhóm trạng thái hay dùng.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (QuotationStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (QuotationStatus $status): string => $status->value, $statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'draft' => Tab::make('Nháp')
                ->modifyQueryUsing($byStatus(QuotationStatus::Draft)),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing($byStatus(QuotationStatus::PendingApproval)),
            'open' => Tab::make('Đã duyệt / Đã gửi')
                ->modifyQueryUsing($byStatus(QuotationStatus::Approved, QuotationStatus::Sent)),
            'accepted' => Tab::make('Khách chấp nhận')
                ->modifyQueryUsing($byStatus(QuotationStatus::Accepted)),
            'closed' => Tab::make('Từ chối / Hết hạn / Huỷ')
                ->modifyQueryUsing($byStatus(QuotationStatus::Rejected, QuotationStatus::Expired, QuotationStatus::Cancelled)),
        ];
    }
}
