<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\InvoiceStatus;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\VietnameseLabels;
use App\Models\Invoice;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class InvoiceResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Tài chính';

    protected static ?string $navigationLabel = 'Hóa đơn';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'hóa đơn';

    protected static ?string $pluralModelLabel = 'hóa đơn';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Số hóa đơn'),
                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->description(fn (Invoice $record): ?string => $record->description)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('invoice_date')
                    ->label('Ngày hóa đơn')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Hạn thanh toán')
                    ->date()
                    ->color(fn (Invoice $record): ?string => $record->isPastDue() ? 'danger' : null)
                    ->sortable(),
                Columns::money('total', 'Tổng tiền', summarize: true),
                Columns::money('remaining_amount', 'Còn phải thu', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(InvoiceStatus::class)
                    ->multiple(),
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('project_id')
                    ->label('Dự án')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
                Filters::dateRange('invoice_date', 'Ngày hóa đơn'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Invoice $record): bool => static::canEdit($record)),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có hóa đơn nào.')
            ->emptyStateDescription('Lập hóa đơn từ biên bản nghiệm thu đã duyệt hoặc theo hợp đồng.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo hóa đơn'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'payments' => PaymentsRelationManager::class,
            'attachments' => AttachmentsRelationManager::class,
            'audits' => ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
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
     * Rule 4: chỉ hóa đơn nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Invoice
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Invoice
            && ! $record->trashed()
            && $record->isEditable()
            && parent::canDelete($record);
    }

    /**
     * Số hóa đơn quá hạn hiển thị ngay trên menu.
     */
    public static function getNavigationBadge(): ?string
    {
        $overdue = Invoice::query()->pastDue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Hóa đơn quá hạn';
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'description', 'customer.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return "{$record->code} — {$record->customer?->name}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Tổng tiền' => Money::format($record->total),
            'Trạng thái' => $record->status->getLabel(),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }
}
