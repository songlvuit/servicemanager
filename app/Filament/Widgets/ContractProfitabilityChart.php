<?php

namespace App\Filament\Widgets;

use App\Enums\ContractStatus;
use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Models\Contract;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

/**
 * Lợi nhuận thực tế của từng hợp đồng (mục 35): giá trị hợp đồng − chi phí
 * thực tế của các công việc gắn với hợp đồng. Chỉ dành cho người vừa xem được
 * hợp đồng vừa xem được chi phí nội bộ.
 */
class ContractProfitabilityChart extends ChartWidget
{
    private const LIMIT = 8;

    protected static ?int $sort = 31;

    protected ?string $heading = 'Lợi nhuận theo hợp đồng';

    protected ?string $description = 'Giá trị hợp đồng − chi phí thực tế, các hợp đồng đang hiệu lực và đã hoàn thành có giá trị lớn nhất.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có hợp đồng';

    public static function canView(): bool
    {
        return Access::can('contracts.view') && Access::canSeeCosts();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $contracts = Contract::query()
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Completed->value])
            ->orderByDesc('contract_value')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'title', 'actual_profit'])
            ->sortByDesc(fn (Contract $contract): float => (float) $contract->actual_profit)
            ->values();

        if ($contracts->isEmpty()) {
            return [];
        }

        return [
            'labels' => $contracts->map(fn (Contract $contract): string => Str::limit($contract->title, 26))->all(),
            'datasets' => [[
                'label' => 'Lợi nhuận thực tế',
                'data' => $contracts->map(fn (Contract $contract): float => (float) $contract->actual_profit)->all(),
                'backgroundColor' => ChartPalette::PRIMARY,
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 18,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis(horizontal: true);
    }
}
