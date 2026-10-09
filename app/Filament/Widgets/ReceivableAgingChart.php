<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Services\Billing\ReceivableAging;
use App\Support\Decimal;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Tuổi nợ phải thu (mục 35): số còn phải thu chia theo số ngày quá hạn.
 */
class ReceivableAgingChart extends ChartWidget
{
    protected static ?int $sort = 32;

    protected ?string $heading = 'Tuổi nợ phải thu';

    protected ?string $description = 'Số còn phải thu của các hóa đơn đang mở, theo số ngày quá hạn.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Không có công nợ phải thu';

    protected ?string $emptyStateDescription = 'Mọi hóa đơn đã phát hành đều đã thu đủ.';

    public static function canView(): bool
    {
        return Access::can('receivables.view');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $summary = app(ReceivableAging::class)->summary();

        if (! Decimal::isPositive($summary['total'])) {
            return [];
        }

        return [
            'labels' => [
                'Chưa tới hạn',
                '1–30 ngày',
                '31–60 ngày',
                '61–90 ngày',
                'Trên 90 ngày',
            ],
            'datasets' => [[
                'label' => 'Còn phải thu',
                'data' => array_map(fn (array $bucket): float => (float) $bucket['amount'], array_values($summary['buckets'])),
                'backgroundColor' => ChartPalette::PRIMARY,
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 44,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis();
    }
}
