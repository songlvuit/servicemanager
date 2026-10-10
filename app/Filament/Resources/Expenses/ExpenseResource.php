<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Filament\Support\VietnameseLabels;
use App\Models\Expense;
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

class ExpenseResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Expense::class;

    protected static ?string $slug = 'expenses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Chi phí';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'đề nghị chi';

    protected static ?string $pluralModelLabel = 'chi phí';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            'attachments' => AttachmentsRelationManager::class,
            'audits' => ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    /**
     * Người không có quyền "Xem tất cả" chỉ thấy đề nghị chi của mình và của dự án mình quản lý.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['project', 'task', 'employee'])
            ->visibleTo(auth()->user())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Chỉ đề nghị chi nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Expense
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Expense
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canDelete($record);
    }

    /**
     * Người duyệt thấy số đề nghị chi đang chờ chính mình duyệt ngay trên menu.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Expense::query()->awaitingApprovalBy(auth()->user())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Đề nghị chi chờ bạn duyệt';
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'description', 'project.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return "{$record->code} — {$record->description}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Dự án' => (string) $record->project?->name,
            'Số tiền' => Money::format($record->total_amount),
        ];
    }
}
