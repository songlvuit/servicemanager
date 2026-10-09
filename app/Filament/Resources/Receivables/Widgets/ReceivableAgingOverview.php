<?php

namespace App\Filament\Resources\Receivables\Widgets;

use App\Filament\Support\Access;
use App\Services\Billing\ReceivableAging;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tổng công nợ phải thu và từng nhóm tuổi nợ (mục 32).
 */
class ReceivableAgingOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        if (! Access::can('receivables.view')) {
            return [];
        }

        $summary = app(ReceivableAging::class)->summary();

        $stats = [
            Stat::make('Tổng phải thu', Money::format($summary['total']))
                ->description($summary['count'].' hóa đơn còn phải thu')
                ->icon(Heroicon::OutlinedScale)
                ->color('primary'),
        ];

        $colors = ['current' => 'success', '1_30' => 'warning', '31_60' => 'danger', '61_90' => 'danger', 'over_90' => 'danger'];

        foreach ($summary['buckets'] as $key => $bucket) {
            $stats[] = Stat::make($bucket['label'], Money::format($bucket['amount']))
                ->description($bucket['count'].' hóa đơn')
                ->color($bucket['count'] > 0 ? $colors[$key] : 'gray');
        }

        return $stats;
    }
}
