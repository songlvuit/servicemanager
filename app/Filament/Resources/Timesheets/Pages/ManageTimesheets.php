<?php

namespace App\Filament\Resources\Timesheets\Pages;

use App\Filament\Resources\Timesheets\TimesheetResource;
use App\Filament\Support\TimesheetExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTimesheets extends ManageRecords
{
    protected static string $resource = TimesheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TimesheetExport::make(),
            CreateAction::make()
                ->label('Thêm công'),
        ];
    }
}
