<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

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
        $byStatus = fn (PurchaseRequestStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (PurchaseRequestStatus $status): string => $status->value, $statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'awaiting_me' => Tab::make('Chờ tôi duyệt')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingApprovalBy(auth()->user())),
            'submitted' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing($byStatus(PurchaseRequestStatus::Submitted)),
            'approved' => Tab::make('Đã duyệt — chờ đặt hàng')
                ->modifyQueryUsing($byStatus(PurchaseRequestStatus::Approved)),
            'ordered' => Tab::make('Đã đặt / đã nhận')
                ->modifyQueryUsing($byStatus(PurchaseRequestStatus::Ordered, PurchaseRequestStatus::Received)),
            'closed' => Tab::make('Hoàn tất / Từ chối / Huỷ')
                ->modifyQueryUsing($byStatus(PurchaseRequestStatus::Completed, PurchaseRequestStatus::Rejected, PurchaseRequestStatus::Cancelled)),
        ];
    }
}
