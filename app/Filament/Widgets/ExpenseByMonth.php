<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use Filament\Support\RawJs;

/**
 * Chi phí theo tháng (mục 35): chi phí thực tế của các dự án — đề nghị chi đã
 * thanh toán, đơn mua hàng đã nhận và giờ công.
 */
class ExpenseByMonth extends FinanceChart
{
    protected static ?int $sort = 21;

    protected ?string $heading = 'Chi phí theo tháng';

    protected ?string $description = 'Chi phí thực tế: đề nghị chi đã thanh toán, đơn mua hàng đã nhận, giờ công.';

    protected ?string $emptyStateHeading = 'Chưa có chi phí';

    protected ?string $emptyStateDescription = 'Chi phí xuất hiện khi có khoản chi được thanh toán hoặc hàng được nhận.';

    public static function canView(): bool
    {
        return Access::can('profit_reports.view');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return $this->series('cost', 'Chi phí', [
            'backgroundColor' => ChartPalette::PRIMARY,
            'borderWidth' => 0,
            'borderRadius' => 4,
            'maxBarThickness' => 28,
        ]);
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis();
    }
}
