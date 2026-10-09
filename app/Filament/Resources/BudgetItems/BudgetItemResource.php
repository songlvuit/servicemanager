<?php

namespace App\Filament\Resources\BudgetItems;

use App\Filament\Resources\BudgetItems\Pages\ManageBudgetItems;
use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Resources\BudgetItems\Tables\BudgetItemsTable;
use App\Filament\Support\VietnameseLabels;
use App\Models\BudgetItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Màn hình "Ngân sách": Ngân sách – Cam kết – Thực tế của mọi dự án, gom
 * theo dự án (đồng thời là báo cáo chi phí dự án — mục 62).
 */
class BudgetItemResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = BudgetItem::class;

    protected static ?string $slug = 'budgets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Ngân sách';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'dòng ngân sách';

    protected static ?string $pluralModelLabel = 'ngân sách';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(BudgetItemForm::components());
    }

    public static function table(Table $table): Table
    {
        return BudgetItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBudgetItems::route('/'),
        ];
    }

    /**
     * Chỉ ngân sách của những dự án người dùng được xem.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('project')
            ->whereHas('project', fn (Builder $query) => $query->visibleTo(auth()->user()));
    }
}
