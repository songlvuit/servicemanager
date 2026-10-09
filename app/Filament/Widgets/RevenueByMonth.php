<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * Doanh thu theo tháng (mục 35): giá trị trước thuế của hóa đơn đã phát hành.
 */
class RevenueByMonth extends FinanceChart
{
    protected static ?int $sort = 20;

    protected ?string $heading = 'Doanh thu theo tháng';

    protected ?string $description = 'Giá trị trước thuế của hóa đơn đã phát hành, theo ngày hóa đơn.';

    protected ?string $emptyStateHeading = 'Chưa có doanh thu';

    protected ?string $emptyStateDescription = 'Doanh thu xuất hiện khi hóa đơn đầu tiên được phát hành.';

    public static function canView(): bool
    {
        return Access::can('invoices.view') || Access::can('profit_reports.view');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return $this->series('revenue', 'Doanh thu', [
            'borderWidth' => 2,
            'tension' => 0.3,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
        ]);
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis();
    }
}
