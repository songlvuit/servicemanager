<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Dòng hàng đã được lưu xong: tính và ghi các cột tổng ở server.
     */
    protected function afterCreate(): void
    {
        app(PurchaseOrderWorkflow::class)->recalculate($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
