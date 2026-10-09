<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\VietnameseLabels;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Thanh toán của khách hàng (mục 31). Chỉ có danh sách: thanh toán được ghi
 * nhận từ hóa đơn (hoặc nút trên trang này) và không sửa, không xoá (Rule 5).
 */
class PaymentResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Tài chính';

    protected static ?string $navigationLabel = 'Thanh toán';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'thanh toán';

    protected static ?string $pluralModelLabel = 'thanh toán';

    public static function table(Table $table): Table
    {
        return static::paymentsTable($table)
            ->filters([
                SelectFilter::make('payment_method')
                    ->label('Hình thức')
                    ->options(PaymentMethod::class)
                    ->multiple(),
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                Filters::dateRange('payment_date', 'Ngày thanh toán'),
            ])
            ->emptyStateHeading('Chưa có thanh toán nào.')
            ->emptyStateDescription('Khi khách hàng trả tiền, ghi nhận thanh toán cho hóa đơn tương ứng.');
    }

    /**
     * Bảng thanh toán dùng chung cho màn hình Thanh toán và các tab Thanh toán.
     */
    public static function paymentsTable(Table $table, bool $withInvoice = true): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Ngày thanh toán')
                    ->date()
                    ->sortable(),
                TextColumn::make('invoice.code')
                    ->label('Hóa đơn')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Payment $record): ?string => $record->customer?->name)
                    ->url(fn (Payment $record): ?string => $record->invoice !== null && InvoiceResource::canView($record->invoice)
                        ? InvoiceResource::getUrl('view', ['record' => $record->invoice])
                        : null)
                    ->searchable()
                    ->visible($withInvoice),
                Columns::money('amount', 'Số tiền', summarize: true),
                TextColumn::make('payment_method')
                    ->label('Hình thức')
                    ->badge(),
                TextColumn::make('reference')
                    ->label('Số chứng từ')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Người ghi nhận')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('payment_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice', 'customer', 'creator']);
    }

    /**
     * Rule 5: thanh toán đã ghi nhận không sửa, không xoá — kể cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
