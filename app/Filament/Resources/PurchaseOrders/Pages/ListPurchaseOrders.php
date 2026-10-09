<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (PurchaseOrderStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (PurchaseOrderStatus $status): string => $status->value, $statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'draft' => Tab::make('Nháp')
                ->modifyQueryUsing($byStatus(PurchaseOrderStatus::Draft)),
            'ordered' => Tab::make('Đã đặt — chờ nhận hàng')
                ->modifyQueryUsing($byStatus(PurchaseOrderStatus::Ordered)),
            'received' => Tab::make('Đã nhận — chờ hoàn tất')
                ->modifyQueryUsing($byStatus(PurchaseOrderStatus::Received)),
            'closed' => Tab::make('Hoàn tất / Đã huỷ')
                ->modifyQueryUsing($byStatus(PurchaseOrderStatus::Completed, PurchaseOrderStatus::Cancelled)),
        ];
    }
}
