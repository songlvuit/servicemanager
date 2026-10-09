<?php

namespace App\Filament\Resources\Acceptances\Pages;

use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Filament\Resources\Acceptances\Actions\AcceptanceActions;
use App\Models\Acceptance;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewAcceptance extends ViewRecord
{
    protected static string $resource = AcceptanceResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Biên bản';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedClipboardDocumentCheck;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Nghiệm thu '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->description;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Acceptance $record): bool => AcceptanceResource::canEdit($record)),
            AcceptanceActions::submit(),
            AcceptanceActions::approve(),
            AcceptanceActions::reject(),
            AcceptanceActions::revise(),
            AcceptanceActions::createInvoice(),
            ActionGroup::make([
                DeleteAction::make()
                    ->visible(fn (Acceptance $record): bool => AcceptanceResource::canDelete($record)),
            ]),
        ];
    }
}
