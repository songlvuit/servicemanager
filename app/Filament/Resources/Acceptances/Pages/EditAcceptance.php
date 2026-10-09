<?php

namespace App\Filament\Resources\Acceptances\Pages;

use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Models\Acceptance;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAcceptance extends EditRecord
{
    protected static string $resource = AcceptanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (Acceptance $record): bool => AcceptanceResource::canDelete($record)),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
