<?php

namespace App\Filament\Resources\Customers;

use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\ContractsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OpportunitiesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\QuotationsRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Filament\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Support\VietnameseLabels;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CustomerResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Kinh doanh';

    protected static ?string $navigationLabel = 'Khách hàng';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'khách hàng';

    protected static ?string $pluralModelLabel = 'khách hàng';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            'contacts' => ContactsRelationManager::class,
            'opportunities' => OpportunitiesRelationManager::class,
            'quotations' => QuotationsRelationManager::class,
            'contracts' => ContractsRelationManager::class,
            'projects' => ProjectsRelationManager::class,
            'invoices' => InvoicesRelationManager::class,
            'payments' => PaymentsRelationManager::class,
            'attachments' => AttachmentsRelationManager::class,
            'audits' => ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    /**
     * Gồm cả khách hàng đã xoá mềm để bộ lọc "Đã xoá" và nút khôi phục hoạt động.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'tax_code', 'email', 'phone'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Mã' => $record->code,
            'MST' => $record->tax_code,
        ]);
    }
}
