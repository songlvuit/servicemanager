<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Filament\Support\Access;
use App\Models\Project;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/**
 * Hàng thẻ số liệu trên dashboard dự án (mục 17): doanh thu, ngân sách, chi
 * phí thực tế / cam kết, ngân sách còn lại, lợi nhuận, biên lợi nhuận, đã
 * thu, còn phải thu, tiến độ. Số liệu chi phí và lợi nhuận chỉ hiện với
 * người có quyền xem chi phí nội bộ.
 */
class ProjectStatsOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public ?Model $record = null;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $project = $this->record;

        // Widget tự kiểm tra lại quyền xem dự án (không dựa vào trang chứa nó).
        if (! $project instanceof Project || ! (auth()->user()?->can('view', $project) ?? false)) {
            return [];
        }

        $stats = [
            Stat::make('Doanh thu', Money::format($project->contract_value))
                ->description('Giá trị hợp đồng của dự án, trước thuế')
                ->icon(Heroicon::OutlinedBanknotes),
        ];

        if (Access::canSeeCosts()) {
            $costStatus = $project->costStatus();
            $remaining = $project->remainingBudget();

            $stats = [
                ...$stats,
                Stat::make('Ngân sách', Money::format($project->budget))
                    ->description($costStatus->getLabel())
                    ->color($costStatus->getColor())
                    ->icon(Heroicon::OutlinedCalculator),
                Stat::make('Chi phí thực tế', Money::format($project->actual_cost))
                    ->description(Decimal::isPositive($project->budget)
                        ? Money::percent(Decimal::ratio($project->actual_cost, $project->budget)).' ngân sách'
                        : 'Chưa có ngân sách')
                    ->color($costStatus->getColor())
                    ->icon(Heroicon::OutlinedReceiptPercent),
                Stat::make('Chi phí đã cam kết', Money::format($project->committed_cost))
                    ->description('Đã duyệt / đã đặt hàng, gồm cả phần đã chi')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck),
                Stat::make('Ngân sách còn lại', Money::format($remaining))
                    ->description('Ngân sách − chi phí đã cam kết')
                    ->color((float) $remaining < 0 ? 'danger' : 'gray')
                    ->icon(Heroicon::OutlinedWallet),
                Stat::make('Lợi nhuận dự kiến', Money::format($project->expected_profit))
                    ->description('Doanh thu − ngân sách')
                    ->color((float) $project->expected_profit < 0 ? 'danger' : 'gray')
                    ->icon(Heroicon::OutlinedArrowTrendingUp),
                Stat::make('Lợi nhuận thực tế', Money::format($project->actual_profit))
                    ->description('Doanh thu − chi phí thực tế')
                    ->color((float) $project->actual_profit < 0 ? 'danger' : 'success')
                    ->icon(Heroicon::OutlinedPresentationChartLine),
                Stat::make('Biên lợi nhuận', Money::percent($project->profit_margin))
                    ->description('Lợi nhuận thực tế / doanh thu')
                    ->color(ProjectInfolist::marginColor($project->profit_margin))
                    ->icon(Heroicon::OutlinedChartPie),
            ];
        }

        $outstanding = $project->outstandingAmount();

        return [
            ...$stats,
            Stat::make('Đã thu', Money::format($project->collected_amount))
                ->description('Trên '.Money::format($project->invoiced_amount).' đã xuất hóa đơn')
                ->color('success')
                ->icon(Heroicon::OutlinedCreditCard),
            Stat::make('Còn phải thu', Money::format($outstanding))
                ->description('Đã xuất hóa đơn, chưa thu')
                ->color(Decimal::isPositive($outstanding) ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedScale),
            Stat::make('Tiến độ', $project->progress.'%')
                ->description($this->taskSummary($project))
                ->color($project->isOverdue() ? 'danger' : 'primary')
                ->icon(Heroicon::OutlinedChartBar),
        ];
    }

    private function taskSummary(Project $project): string
    {
        $total = $project->tasks()->count();

        if ($total === 0) {
            return 'Chưa có công việc';
        }

        $done = $project->tasks()->where('status', 'done')->count();

        return "{$done}/{$total} công việc hoàn thành";
    }
}
