<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Timesheets\Schemas\TimesheetForm;
use App\Filament\Resources\Timesheets\Tables\TimesheetsTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Chấm công" của dự án: giờ công ghi cho dự án (mục 28).
 */
class TimesheetsRelationManager extends RelationManager
{
    protected static string $relationship = 'timesheets';

    protected static ?string $title = 'Chấm công';

    protected static ?string $modelLabel = 'giờ công';

    protected static ?string $pluralModelLabel = 'giờ công';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('timesheets.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(TimesheetForm::components(withProject: false));
    }

    public function table(Table $table): Table
    {
        return TimesheetsTable::configure($table, withProject: false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'task'])->visibleTo(auth()->user()))
            ->headerActions([
                CreateAction::make()
                    ->label('Ghi giờ công')
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isClosed() && ! $this->getOwnerRecord()->trashed()),
            ]);
    }
}
