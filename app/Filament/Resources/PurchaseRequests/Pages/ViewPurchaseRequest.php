<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\Actions\PurchaseRequestActions;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Yêu cầu mua hàng '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (PurchaseRequest $record): bool => PurchaseRequestResource::canEdit($record)),
            PurchaseRequestActions::submit(),
            PurchaseRequestActions::approve(),
            PurchaseRequestActions::reject(),
            PurchaseRequestActions::revise(),
            PurchaseRequestActions::createOrder(),
            ActionGroup::make([
                PurchaseRequestActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (PurchaseRequest $record): bool => PurchaseRequestResource::canDelete($record)),
            ]),
        ];
    }
}
