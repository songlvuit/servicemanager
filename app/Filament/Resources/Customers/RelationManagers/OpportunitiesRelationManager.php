<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Opportunities\Schemas\OpportunityForm;
use App\Filament\Resources\Opportunities\Tables\OpportunitiesTable;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Dùng lại form và bảng của màn hình Cơ hội; trường / cột "Khách hàng" tự ẩn
 * vì đã biết khách hàng.
 */
class OpportunitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'opportunities';

    protected static ?string $title = 'Cơ hội';

    protected static ?string $modelLabel = 'cơ hội';

    protected static ?string $pluralModelLabel = 'cơ hội';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedSparkles;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return OpportunityForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return OpportunitiesTable::configure($table)
            ->recordTitleAttribute('title')
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
