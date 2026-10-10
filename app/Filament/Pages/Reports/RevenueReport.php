<?php

namespace App\Filament\Pages\Reports;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TableExport;
use App\Models\Invoice;
use App\Models\Service;
use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Báo cáo doanh thu (mục 62): các hóa đơn đã phát hành (không tính nháp và đã
 * huỷ). Doanh thu là giá trị TRƯỚC THUẾ, ghi nhận theo ngày hóa đơn.
 */
class RevenueReport extends ReportPage
{
    protected static ?string $slug = 'reports/revenue';

    protected static ?string $title = 'Báo cáo doanh thu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 41;

    protected ?string $subheading = 'Hóa đơn đã phát hành. Doanh thu là giá trị trước thuế, ghi nhận theo ngày hóa đơn.';

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('profit_reports.export', 'bao-cao-doanh-thu', 'Báo cáo doanh thu', fn (): array => [
                'Ngày hóa đơn' => 'invoice_date',
                'Số hóa đơn' => 'code',
                'Khách hàng' => 'customer.name',
                'Hợp đồng' => 'contract.code',
                'Trạng thái' => 'status',
                'Doanh thu (trước thuế)' => 'subtotal',
                'Thuế GTGT' => 'tax',
                'Tổng thanh toán' => 'total',
                'Đã thu' => 'paid_amount',
                'Còn phải thu' => 'remaining_amount',
                'Hạn thanh toán' => 'due_date',
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Invoice::query()->billed()->with(['customer', 'contract']))
            ->columns([
                TextColumn::make('invoice_date')
                    ->label('Ngày hóa đơn')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Số hóa đơn')
                    ->weight(FontWeight::Medium)
                    ->url(fn (Invoice $record): ?string => InvoiceResource::canView($record)
                        ? InvoiceResource::getUrl('view', ['record' => $record])
                        : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('contract.code')
                    ->label('Hợp đồng')
                    ->description(fn (Invoice $record): ?string => $record->contract?->title)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->toggleable(),
                Columns::money('subtotal', 'Doanh thu', summarize: true)
                    ->weight(FontWeight::SemiBold),
                Columns::money('tax', 'Thuế GTGT', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('total', 'Tổng thanh toán', summarize: true)
                    ->toggleable(),
                Columns::money('paid_amount', 'Đã thu', summarize: true)
                    ->toggleable(),
                Columns::money('remaining_amount', 'Còn phải thu', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filters::dateRange('invoice_date', 'Ngày hóa đơn'),
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('contract_id')
                    ->label('Hợp đồng')
                    ->relationship('contract', 'code')
                    ->searchable()
                    ->preload(),
                // Hóa đơn thuộc hợp đồng lập từ báo giá có dịch vụ này.
                SelectFilter::make('service')
                    ->label('Dịch vụ')
                    ->options(fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $service): Builder => $query->whereHas(
                            'contract.quotation.items',
                            fn (Builder $query) => $query->where('service_id', $service),
                        ),
                    )),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(collect(InvoiceStatus::billed())->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->multiple(),
            ])
            ->groups([
                Group::make('customer.name')->label('Khách hàng')->collapsible(),
                Group::make('contract.code')->label('Hợp đồng')->collapsible(),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedArrowTrendingUp)
            ->emptyStateHeading('Chưa có doanh thu.')
            ->emptyStateDescription('Doanh thu được ghi nhận khi hóa đơn được phát hành. Thử nới bộ lọc nếu bạn đang lọc theo ngày.');
    }
}
