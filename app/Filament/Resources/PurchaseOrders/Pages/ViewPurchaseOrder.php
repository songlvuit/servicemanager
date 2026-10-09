<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Đơn mua hàng '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->supplier?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record)),
            PurchaseOrderActions::order(),
            PurchaseOrderActions::receive(),
            PurchaseOrderActions::complete(),
            ActionGroup::make([
                PurchaseOrderActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canDelete($record)),
            ]),
        ];
    }
}
