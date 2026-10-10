<?php

namespace App\Filament\Pages\Reports;

use App\Enums\MarginBand;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TableExport;
use App\Models\Project;
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
 * Báo cáo lợi nhuận theo dự án (mục 37, 62):
 *
 *   Doanh thu  = giá trị hợp đồng của dự án (trước thuế)
 *   Lợi nhuận  = Doanh thu − Chi phí thực tế
 *   Biên LN    = Lợi nhuận / Doanh thu × 100
 */
class ProfitReport extends ReportPage
{
    protected static ?string $slug = 'reports/profit';

    protected static ?string $title = 'Báo cáo lợi nhuận';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 40;

    protected ?string $subheading = 'Lợi nhuận từng dự án: giá trị hợp đồng − chi phí thực tế, trước thuế. Chi phí chung không gắn dự án không nằm ở đây — xem Báo cáo chi phí.';

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('profit_reports.export', 'bao-cao-loi-nhuan', 'Báo cáo lợi nhuận', fn (): array => [
                'Mã dự án' => 'code',
                'Dự án' => 'name',
                'Khách hàng' => 'customer.name',
                'Trạng thái' => 'status',
                'Ngày bắt đầu' => 'start_date',
                'Ngày kết thúc' => 'end_date',
                'Doanh thu (giá trị hợp đồng)' => 'contract_value',
                'Ngân sách' => 'budget',
                'Chi phí thực tế' => 'actual_cost',
                'Chi phí nhân sự' => 'labor_cost',
                'Chi phí nhà cung cấp' => 'supplier_cost',
                'Chi phí thiết bị' => 'equipment_cost',
                'Chi phí khác' => 'other_cost',
                'Lợi nhuận' => 'actual_profit',
                'Biên lợi nhuận (%)' => 'profit_margin',
                'Đánh giá' => fn (Project $project): string => static::band($project)?->getLabel() ?? 'Chưa có chi phí',
                'Đã xuất hóa đơn (gồm thuế)' => 'invoiced_amount',
                'Đã thu' => 'collected_amount',
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        $money = fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0);

        return $table
            ->query(fn (): Builder => Project::query()->with('customer')->visibleTo(auth()->user()))
            ->columns([
                TextColumn::make('name')
                    ->label('Dự án')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Project $record): string => $record->code.' · '.($record->customer?->name ?? '—'))
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
                    ->searchable(['name', 'code'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->toggleable(),
                Columns::money('contract_value', 'Doanh thu', summarize: true),
                Columns::money('budget', 'Ngân sách', summarize: true)
                    ->toggleable(),
                Columns::money('actual_cost', 'Chi phí thực tế', summarize: true),
                Columns::money('labor_cost', 'Nhân sự', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('supplier_cost', 'Nhà cung cấp', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('equipment_cost', 'Thiết bị', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('other_cost', 'Chi phí khác', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('actual_profit', 'Lợi nhuận', summarize: true)
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Project $record): ?string => (float) $record->actual_profit < 0 ? 'danger' : null),
                TextColumn::make('profit_margin')
                    ->label('Biên LN')
                    ->formatStateUsing(fn (mixed $state): string => Money::percent(is_scalar($state) ? $state : 0))
                    ->badge()
                    ->color(fn (Project $record): string => static::band($record)?->getColor() ?? 'gray')
                    ->alignEnd()
                    ->sortable()
                    // Biên lợi nhuận chung = Σ lợi nhuận / Σ doanh thu (không phải trung bình các dự án).
                    ->summarize(Summarizer::make()
                        ->label('Chung')
                        ->using(fn (QueryBuilder $query): string => Decimal::ratio(
                            (clone $query)->sum('actual_profit'),
                            (clone $query)->sum('contract_value'),
                        ))
                        ->formatStateUsing(fn (mixed $state): string => Money::percent(is_scalar($state) ? $state : 0))),
                TextColumn::make('margin_band')
                    ->label('Đánh giá')
                    ->state(fn (Project $record): ?MarginBand => static::band($record))
                    ->badge()
                    ->placeholder('Chưa có chi phí')
                    ->toggleable(),
                Columns::money('invoiced_amount', 'Đã xuất hóa đơn', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::money('collected_amount', 'Đã thu', summarize: true)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('id')
                    ->label('Dự án')
                    ->options(fn (): array => Project::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ProjectStatus::class)
                    ->multiple(),
                SelectFilter::make('margin_band')
                    ->label('Đánh giá biên lợi nhuận')
                    ->options(MarginBand::class)
                    ->query(fn (Builder $query, array $data): Builder => static::applyMarginBand($query, $data['value'] ?? null)),
                Filters::dateRange('start_date', 'Ngày bắt đầu'),
            ])
            ->defaultSort('contract_value', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading('Chưa có dự án nào.')
            ->emptyStateDescription('Báo cáo lợi nhuận hiện số liệu của từng dự án ngay khi dự án được tạo từ hợp đồng.');
    }

    /**
     * Đánh giá biên lợi nhuận của dự án. Dự án chưa phát sinh chi phí (biên
     * 100%) hoặc chưa có doanh thu thì chưa đánh giá được.
     */
    public static function band(Project $project): ?MarginBand
    {
        if (! Decimal::isPositive($project->actual_cost) || ! Decimal::isPositive($project->contract_value)) {
            return null;
        }

        return MarginBand::for($project->profit_margin);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function applyMarginBand(Builder $query, ?string $band): Builder
    {
        if (MarginBand::tryFrom((string) $band) !== null) {
            // Chỉ xét những dự án đã đánh giá được (xem band()).
            $query->where('actual_cost', '>', 0)->where('contract_value', '>', 0);
        }

        $danger = (float) config('project.margin_danger', 10);
        $warning = (float) config('project.margin_warning', 20);
        $good = (float) config('project.margin_good', 30);

        return match (MarginBand::tryFrom((string) $band)) {
            MarginBand::Danger => $query->where('profit_margin', '<', $danger),
            MarginBand::Warning => $query->where('profit_margin', '>=', $danger)->where('profit_margin', '<', $warning),
            MarginBand::Normal => $query->where('profit_margin', '>=', $warning)->where('profit_margin', '<=', $good),
            MarginBand::Good => $query->where('profit_margin', '>', $good),
            default => $query,
        };
    }
}
