<?php

namespace App\Filament\Resources\Contracts;

use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Schemas\ContractInfolist;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Filament\Support\VietnameseLabels;
use App\Models\Contract;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ContractResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Contract::class;

    protected static ?string $slug = 'contracts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Kinh doanh';

    protected static ?string $navigationLabel = 'Hợp đồng';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'hợp đồng';

    protected static ?string $pluralModelLabel = 'hợp đồng';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContractInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            'projects' => ProjectsRelationManager::class,
            'invoices' => InvoicesRelationManager::class,
            'attachments' => AttachmentsRelationManager::class,
            'audits' => ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'view' => ViewContract::route('/{record}'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Chỉ hợp đồng nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Contract
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Contract
            && $record->isEditable()
            && parent::canDelete($record);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'title', 'customer.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return "{$record->code} — {$record->title}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Khách hàng' => (string) $record->customer?->name,
            'Giá trị' => Money::format($record->contract_value),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }
}
