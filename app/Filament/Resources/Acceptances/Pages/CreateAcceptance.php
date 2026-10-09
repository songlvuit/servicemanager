<?php

namespace App\Filament\Resources\Acceptances\Pages;

use App\Filament\Resources\Acceptances\AcceptanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAcceptance extends CreateRecord
{
    protected static string $resource = AcceptanceResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
