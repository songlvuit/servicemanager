<?php

namespace App\Filament\Widgets;

use App\Services\Reports\FinancialReport;
use Filament\Widgets\ChartWidget;

/**
 * Nền chung cho các biểu đồ "theo tháng" trên dashboard: bộ lọc 6 / 12 / 24
 * tháng và số liệu lấy từ FinancialReport.
 */
abstract class FinanceChart extends ChartWidget
{
    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    public ?string $filter = '12';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '6' => '6 tháng',
            '12' => '12 tháng',
            '24' => '24 tháng',
        ];
    }

    /**
     * @return list<array{key: string, label: string, revenue: string, cost: string, profit: string, margin: string}>
     */
    protected function months(): array
    {
        $months = in_array($this->filter, ['6', '12', '24'], true) ? (int) $this->filter : 12;

        return app(FinancialReport::class)->monthly($months);
    }

    /**
     * Dữ liệu một chuỗi số liệu theo tháng; trả về mảng rỗng (biểu đồ hiện
     * "chưa có dữ liệu") khi mọi tháng đều bằng 0.
     *
     * @param  'revenue'|'cost'|'profit'  $measure
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    protected function series(string $measure, string $label, array $dataset = []): array
    {
        $months = $this->months();
        $values = array_map(fn (array $month): float => (float) $month[$measure], $months);

        if (array_filter($values) === []) {
            return [];
        }

        return [
            'labels' => array_column($months, 'label'),
            'datasets' => [[
                'label' => $label,
                'data' => $values,
                ...$dataset,
            ]],
        ];
    }
}
