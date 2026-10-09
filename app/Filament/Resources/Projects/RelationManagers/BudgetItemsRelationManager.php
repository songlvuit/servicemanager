<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Resources\BudgetItems\Tables\BudgetItemsTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Ngân sách" của dự án: Ngân sách – Cam kết – Thực tế theo nhóm chi phí.
 */
class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static ?string $title = 'Ngân sách';

    protected static ?string $modelLabel = 'dòng ngân sách';

    protected static ?string $pluralModelLabel = 'ngân sách';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedCalculator;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('budgets.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(BudgetItemForm::components(withProject: false));
    }

    public function table(Table $table): Table
    {
        return BudgetItemsTable::configure($table, withProject: false)
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm ngân sách')
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isClosed() && ! $this->getOwnerRecord()->trashed()),
            ]);
    }
}
