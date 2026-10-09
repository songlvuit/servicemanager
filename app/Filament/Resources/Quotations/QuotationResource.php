<?php

namespace App\Filament\Resources\Quotations;

use App\Enums\QuotationStatus;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Resources\Quotations\Schemas\QuotationInfolist;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Filament\Support\Access;
use App\Filament\Support\VietnameseLabels;
use App\Models\Quotation;
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

class QuotationResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Quotation::class;

    protected static ?string $slug = 'quotations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Kinh doanh';

    protected static ?string $navigationLabel = 'Báo giá';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'báo giá';

    protected static ?string $pluralModelLabel = 'báo giá';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuotationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
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
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'creator'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Chỉ báo giá nháp mới sửa được — áp dụng cho cả Super Admin (người được
     * bỏ qua policy), để số liệu đã gửi duyệt không bị thay đổi.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Quotation
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Quotation
            && $record->status->isDeletable()
            && parent::canDelete($record);
    }

    /**
     * Người có quyền duyệt thấy số báo giá đang chờ duyệt ngay trên menu.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Access::can('quotations.approve')) {
            return null;
        }

        $pending = Quotation::query()
            ->where('status', QuotationStatus::PendingApproval->value)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Báo giá chờ duyệt';
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
            'Tổng cộng' => Money::format($record->total),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }
}
