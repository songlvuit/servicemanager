<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\RelationManagers\TimesheetsRelationManager as ProjectTimesheets;
use App\Filament\Resources\Timesheets\Schemas\TimesheetForm;
use Filament\Actions\CreateAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Tab "Chấm công" của công việc: giờ công ghi cho công việc này (dự án của
 * dòng giờ công tự theo dự án của công việc).
 */
class TimesheetsRelationManager extends ProjectTimesheets
{
    public function form(Schema $schema): Schema
    {
        return $schema->components(TimesheetForm::components(withProject: false, withTask: false));
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                CreateAction::make()
                    ->label('Ghi giờ công')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status !== TaskStatus::Cancelled),
            ]);
    }
}
