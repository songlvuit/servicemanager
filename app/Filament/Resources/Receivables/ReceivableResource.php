<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Widgets\ReceivableAgingOverview;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\VietnameseLabels;
use App\Models\Invoice;
use App\Services\Billing\ReceivableAging;
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
 * Công nợ phải thu (mục 32): các hóa đơn đã phát hành còn phải thu, kèm tuổi
 * nợ. Màn hình chỉ xem, dùng quyền của module "Công nợ" (không phải quyền hóa đơn).
 */
class ReceivableResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'receivables';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Tài chính';

    protected static ?string $navigationLabel = 'Công nợ';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'khoản phải thu';

    protected static ?string $pluralModelLabel = 'công nợ phải thu';

    public static function table(Table $table): Table
    {
        $aging = app(ReceivableAging::class);

        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Hóa đơn')
                    ->searchable()
                    ->url(fn (Invoice $record): ?string => InvoiceResource::canView($record)
                        ? InvoiceResource::getUrl('view', ['record' => $record])
                        : null),
                TextColumn::make('due_date')
                    ->label('Hạn thanh toán')
                    ->date()
                    ->sortable(),
                Columns::money('total', 'Tổng tiền', summarize: true),
                Columns::money('paid_amount', 'Đã thu', summarize: true),
                Columns::money('remaining_amount', 'Còn phải thu', summarize: true)
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('days_overdue')
                    ->label('Số ngày quá hạn')
                    ->state(fn (Invoice $record): int => $record->daysOverdue())
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} ngày" : '—')
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null)
                    ->alignEnd(),
                TextColumn::make('aging_bucket')
                    ->label('Tuổi nợ')
                    ->state(fn (Invoice $record): string => ReceivableAging::label($aging->bucketFor($record)))
                    ->badge()
                    ->color(fn (Invoice $record): string => match ($aging->bucketFor($record)) {
                        'current' => 'success',
                        '1_30' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('aging')
                    ->label('Tuổi nợ')
                    ->options(ReceivableAging::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $aging->scopeBucket($query, $data['value'])
                        : $query),
            ])
            ->recordUrl(fn (Invoice $record): ?string => InvoiceResource::canView($record)
                ? InvoiceResource::getUrl('view', ['record' => $record])
                : null)
            ->defaultSort('due_date')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Không có khoản phải thu nào.')
            ->emptyStateDescription('Mọi hóa đơn đã phát hành đều đã thu đủ.');
    }

    public static function getWidgets(): array
    {
        return [
            ReceivableAgingOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
        ];
    }

    /**
     * Chỉ hóa đơn đã phát hành và còn phải thu.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer')->open();
    }

    public static function canViewAny(): bool
    {
        return Access::can('receivables.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

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
