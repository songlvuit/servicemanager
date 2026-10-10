<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\RelationManagers\PurchasesRelationManager as ProjectPurchases;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tab "Mua hàng" của công việc: các đơn mua hàng gắn vào công việc này.
 */
class PurchasesRelationManager extends ProjectPurchases
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                Action::make('createRequest')
                    ->label('Tạo yêu cầu mua hàng')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->getOwnerRecord()->status !== TaskStatus::Cancelled && PurchaseRequestResource::canCreate())
                    ->url(fn (): string => PurchaseRequestResource::getUrl('create', ['task' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
