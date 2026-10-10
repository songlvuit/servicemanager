<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\Actions\ContractActions;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang chi tiết hợp đồng: thông tin, chi phí và lợi nhuận, các bước của quy
 * trình, công việc thực hiện hợp đồng, hóa đơn và tài liệu.
 */
class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Tổng quan';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedInformationCircle;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Hợp đồng '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Contract $record): bool => ContractResource::canEdit($record)),
            ContractActions::sendForSignature(),
            ContractActions::activate(),
            ContractActions::complete(),
            ActionGroup::make([
                ContractActions::returnToDraft(),
                ContractActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (Contract $record): bool => ! $record->trashed() && ContractResource::canDelete($record)),
                RestoreAction::make(),
            ]),
        ];
    }
}
