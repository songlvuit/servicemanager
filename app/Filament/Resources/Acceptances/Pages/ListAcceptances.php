<?php

namespace App\Filament\Resources\Acceptances\Pages;

use App\Filament\Resources\Acceptances\AcceptanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcceptances extends ListRecords
{
    protected static string $resource = AcceptanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
