<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Services\Reports\RevenueByService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Doanh thu theo dịch vụ (mục 35) trong 12 tháng gần nhất.
 */
class RevenueByServiceChart extends ChartWidget
{
    protected static ?int $sort = 23;

    protected ?string $heading = 'Doanh thu theo dịch vụ';

    protected ?string $description = '12 tháng gần nhất. Doanh thu hóa đơn được chia theo tỷ trọng hạng mục trong báo giá.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có doanh thu';

    public static function canView(): bool
    {
        return Access::can('invoices.view') || Access::can('profit_reports.view');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $revenue = app(RevenueByService::class)->between(today()->subMonthsNoOverflow(11)->startOfMonth(), today()->endOfMonth());

        if ($revenue === []) {
            return [];
        }

        $labels = array_keys($revenue);

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Doanh thu',
                'data' => array_map(fn (string $amount): float => (float) $amount, array_values($revenue)),
                'backgroundColor' => ChartPalette::forLabels($labels, [RevenueByService::OTHER]),
                'borderWidth' => 2,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::doughnut();
    }
}
