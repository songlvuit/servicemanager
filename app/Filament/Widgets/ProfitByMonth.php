<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * Lợi nhuận theo tháng (mục 35): doanh thu − chi phí thực tế của từng tháng.
 */
class ProfitByMonth extends FinanceChart
{
    protected static ?int $sort = 22;

    protected ?string $heading = 'Lợi nhuận theo tháng';

    protected ?string $description = 'Doanh thu − chi phí thực tế. Tháng âm nghĩa là chi nhiều hơn số đã xuất hóa đơn.';

    protected ?string $emptyStateHeading = 'Chưa có số liệu lợi nhuận';

    public static function canView(): bool
    {
        return Access::can('profit_reports.view');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return $this->series('profit', 'Lợi nhuận', [
            'borderWidth' => 2,
            'tension' => 0.3,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            // Tô vùng giữa đường lợi nhuận và mốc 0.
            'fill' => 'origin',
        ]);
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis();
    }
}
