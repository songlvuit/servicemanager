<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ContractStatus;
use App\Enums\MarginBand;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TableExport;
use App\Models\Contract;
use App\Support\Decimal;
use App\Support\Money;
use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Báo cáo lợi nhuận theo HỢP ĐỒNG (mục 37, 62):
 *
 *   Doanh thu  = giá trị hợp đồng (trước thuế)
 *   Chi phí    = Σ chi phí thực tế của các công việc gắn với hợp đồng
 *   Lợi nhuận  = Doanh thu − Chi phí
 *   Biên LN    = Lợi nhuận / Doanh thu × 100
 *
 * Số liệu do ContractFinancials tính sẵn trên từng hợp đồng. Không gồm hợp
 * đồng nháp (chưa có hiệu lực) và hợp đồng đã huỷ, trừ khi lọc riêng.
 */
class ProfitReport extends ReportPage
{
    protected static ?string $slug = 'reports/profit';

    protected static ?string $title = 'Báo cáo lợi nhuận';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 40;

    protected ?string $subheading = 'Lợi nhuận từng hợp đồng: giá trị hợp đồng − chi phí thực tế của các công việc gắn với hợp đồng, trước thuế. Chi phí chung (không gắn công việc, hoặc công việc không có hợp đồng) không nằm ở đây — xem Báo cáo chi phí.';

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('profit_reports.export', 'bao-cao-loi-nhuan', 'Báo cáo lợi nhuận', fn (): array => [
                'Số hợp đồng' => 'code',
                'Hợp đồng' => 'title',
                'Khách hàng' => 'customer.name',
                'Trạng thái' => 'status',
                'Ngày ký' => 'sign_date',
                'Ngày kết thúc' => 'end_date',
                'Doanh thu (giá trị hợp đồng)' => 'contract_value',
                'Ngân sách các công việc' => 'budget',
                'Chi phí đã cam kết' => 'committed_cost',
                'Chi phí thực tế' => 'actual_cost',
                'Lợi nhuận' => 'actual_profit',
                'Biên lợi nhuận (%)' => 'profit_margin',
                'Đánh giá' => fn (Contract $contract): string => static::band($contract)?->getLabel() ?? 'Chưa có chi phí',
                'Đã xuất hóa đơn (gồm thuế)' => 'invoiced_amount',
                'Đã thu' => 'collected_amount',
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        $money = fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0);

        return $table
            ->query(fn (): Builder => Contract::query()->with('customer'))
            ->columns([
                TextColumn::make('title')
                    ->label('Hợp đồng')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Contract $record): string => $record->code.' · '.($record->customer?->name ?? '—'))
                    ->url(fn (Contract $record): string => ContractResource::getUrl('view', ['record' => $record]))
                    ->searchable(['title', 'code'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->toggleable(),
                Columns::money('contract_value', 'Doanh thu', summarize: true),
                Columns::money('budget', 'Ngân sách', summarize: true)
                    ->toggleable(),
                Columns::money('committed_cost', 'Đã cam kết', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('actual_cost', 'Chi phí thực tế', summarize: true),
                Columns::money('actual_profit', 'Lợi nhuận', summarize: true)
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Contract $record): ?string => (float) $record->actual_profit < 0 ? 'danger' : null),
                TextColumn::make('profit_margin')
                    ->label('Biên LN')
                    ->formatStateUsing(fn (mixed $state): string => Money::percent(is_scalar($state) ? $state : 0))
                    ->badge()
                    ->color(fn (Contract $record): string => static::band($record)?->getColor() ?? 'gray')
                    ->alignEnd()
                    ->sortable()
                    // Biên lợi nhuận chung = Σ lợi nhuận / Σ doanh thu (không phải trung bình các hợp đồng).
                    ->summarize(Summarizer::make()
                        ->label('Chung')
                        ->using(fn (QueryBuilder $query): string => Decimal::ratio(
                            (clone $query)->sum('actual_profit'),
                            (clone $query)->sum('contract_value'),
                        ))
                        ->formatStateUsing(fn (mixed $state): string => Money::percent(is_scalar($state) ? $state : 0))),
                TextColumn::make('margin_band')
                    ->label('Đánh giá')
                    ->state(fn (Contract $record): ?MarginBand => static::band($record))
                    ->badge()
                    ->placeholder('Chưa có chi phí')
                    ->toggleable(),
                Columns::money('invoiced_amount', 'Đã xuất hóa đơn', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('collected_amount', 'Đã thu', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ContractStatus::class)
                    ->multiple()
                    // Mặc định: hợp đồng đang hiệu lực và đã hoàn thành.
                    ->default([ContractStatus::Active->value, ContractStatus::Completed->value]),
                SelectFilter::make('margin_band')
                    ->label('Đánh giá biên lợi nhuận')
                    ->options(MarginBand::class)
                    ->query(fn (Builder $query, array $data): Builder => static::applyMarginBand($query, $data['value'] ?? null)),
                Filters::dateRange('sign_date', 'Ngày ký'),
            ])
            ->defaultSort('contract_value', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading('Chưa có hợp đồng nào.')
            ->emptyStateDescription('Báo cáo hiện lợi nhuận của từng hợp đồng đang hiệu lực / đã hoàn thành; gắn công việc vào hợp đồng để chi phí được tính vào đây.');
    }

    /**
     * Đánh giá biên lợi nhuận của hợp đồng. Hợp đồng chưa phát sinh chi phí
     * (biên 100%) hoặc chưa có giá trị thì chưa đánh giá được.
     */
    public static function band(Contract $contract): ?MarginBand
    {
        if (! Decimal::isPositive($contract->actual_cost) || ! Decimal::isPositive($contract->contract_value)) {
            return null;
        }

        return MarginBand::for($contract->profit_margin);
    }

    /**
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public static function applyMarginBand(Builder $query, ?string $band): Builder
    {
        if (MarginBand::tryFrom((string) $band) !== null) {
            // Chỉ xét những hợp đồng đã đánh giá được (xem band()).
            $query->where('actual_cost', '>', 0)->where('contract_value', '>', 0);
        }

        $danger = (float) config('job.margin_danger', 10);
        $warning = (float) config('job.margin_warning', 20);
        $good = (float) config('job.margin_good', 30);

        return match (MarginBand::tryFrom((string) $band)) {
            MarginBand::Danger => $query->where('profit_margin', '<', $danger),
            MarginBand::Warning => $query->where('profit_margin', '>=', $danger)->where('profit_margin', '<', $warning),
            MarginBand::Normal => $query->where('profit_margin', '>=', $warning)->where('profit_margin', '<=', $good),
            MarginBand::Good => $query->where('profit_margin', '>', $good),
            default => $query,
        };
    }
}
