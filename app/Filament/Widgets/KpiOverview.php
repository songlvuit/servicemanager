<?php

namespace App\Filament\Widgets;

use App\Enums\ExpenseStatus;
use App\Enums\MarginBand;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Task;
use App\Services\Billing\ReceivableAging;
use App\Services\Reports\FinancialReport;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Hàng thẻ KPI đầu dashboard (mục 35). Mỗi thẻ chỉ hiện với người có quyền
 * xem số liệu đó; người không xem được số liệu tài chính (nhân viên, quản lý
 * công việc…) thấy các thẻ về công việc của chính mình.
 */
class KpiOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $seesRevenue = Access::can('invoices.view') || Access::can('profit_reports.view');
        $seesProfit = Access::can('profit_reports.view');

        $stats = [];

        if ($seesRevenue || $seesProfit) {
            $report = app(FinancialReport::class);
            $current = $report->month();
            $previous = $report->month(today()->subMonthNoOverflow());
            $trend = $report->monthly(6);

            if ($seesRevenue) {
                $stats[] = Stat::make('Doanh thu tháng này', Money::format($current['revenue']))
                    ->icon(Heroicon::OutlinedArrowTrendingUp)
                    ->description($this->change($current['revenue'], $previous['revenue']))
                    ->descriptionIcon($this->changeIcon($current['revenue'], $previous['revenue']))
                    ->color($this->changeColor($current['revenue'], $previous['revenue'], higherIsBetter: true))
                    ->chart(array_map(fn (array $month): float => (float) $month['revenue'], $trend))
                    ->url(Access::can('invoices.view') ? InvoiceResource::getUrl('index') : null);
            }

            if ($seesProfit) {
                $stats[] = Stat::make('Chi phí tháng này', Money::format($current['cost']))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description($this->change($current['cost'], $previous['cost']))
                    ->descriptionIcon($this->changeIcon($current['cost'], $previous['cost']))
                    ->color($this->changeColor($current['cost'], $previous['cost'], higherIsBetter: false))
                    ->chart(array_map(fn (array $month): float => (float) $month['cost'], $trend));

                $stats[] = Stat::make('Lợi nhuận tháng này', Money::format($current['profit']))
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->description('Doanh thu − chi phí thực tế, trước thuế')
                    ->color((float) $current['profit'] < 0 ? 'danger' : 'success');

                $stats[] = Stat::make('Biên lợi nhuận', Decimal::isPositive($current['revenue']) ? Money::percent($current['margin']) : '—')
                    ->icon(Heroicon::OutlinedChartPie)
                    ->description(Decimal::isPositive($current['revenue'])
                        ? 'Tháng trước: '.(Decimal::isPositive($previous['revenue']) ? Money::percent($previous['margin']) : '—')
                        : 'Tháng này chưa có doanh thu')
                    ->color(Decimal::isPositive($current['revenue']) ? MarginBand::for($current['margin'])->getColor() : 'gray');
            }
        }

        if (Access::can('receivables.view')) {
            $aging = app(ReceivableAging::class)->summary();
            $overdueCount = Invoice::query()->pastDue()->count();
            $overdueAmount = Invoice::query()->pastDue()->sum('remaining_amount');

            $stats[] = Stat::make('Công nợ phải thu', Money::format($aging['total']))
                ->icon(Heroicon::OutlinedScale)
                ->description($overdueCount > 0
                    ? "{$overdueCount} hóa đơn quá hạn · ".Money::format($overdueAmount)
                    : "{$aging['count']} hóa đơn đang mở, không có hóa đơn quá hạn")
                ->color($overdueCount > 0 ? 'danger' : 'gray')
                ->url(ReceivableResource::getUrl('index'));
        }

        if (Access::can('tasks.view_all')) {
            $open = Task::query()->open()->count();
            $overBudget = Access::canSeeCosts() ? Task::query()->overBudget()->count() : 0;
            $overdue = Task::query()->overdue()->count();

            $stats[] = Stat::make('Công việc đang mở', (string) $open)
                ->icon(Heroicon::OutlinedRectangleStack)
                ->description(match (true) {
                    $overBudget > 0 => "{$overBudget} công việc vượt ngân sách",
                    $overdue > 0 => "{$overdue} công việc quá hạn",
                    default => 'Không có việc quá hạn',
                })
                ->color($overBudget > 0 || $overdue > 0 ? 'danger' : 'gray')
                ->url(TaskResource::getUrl('index', ['tab' => $overBudget > 0 ? 'over_budget' : 'open']));
        }

        // Người không xem số liệu tài chính: thẻ về việc của chính mình.
        if (! $seesRevenue && ! $seesProfit) {
            $stats = [...$stats, ...$this->personalStats()];
        }

        return $stats;
    }

    /**
     * @return array<Stat>
     */
    private function personalStats(): array
    {
        $stats = [];

        if (Access::can('tasks.view')) {
            $open = Task::query()->open()->where('assignee_id', auth()->id())->count();
            $overdue = Task::query()->overdue()->where('assignee_id', auth()->id())->count();

            $stats[] = Stat::make('Công việc của tôi', (string) $open)
                ->icon(Heroicon::OutlinedRectangleStack)
                ->description($overdue > 0 ? "{$overdue} việc đã quá hạn" : 'Không có việc quá hạn')
                ->color($overdue > 0 ? 'danger' : 'gray')
                ->url(TaskResource::getUrl('index', ['tab' => 'mine']));
        }

        if (Access::can('expenses.view')) {
            $pending = Expense::query()
                ->where('employee_id', auth()->id())
                ->whereIn('status', array_map(fn (ExpenseStatus $status): string => $status->value, ExpenseStatus::inApproval()))
                ->count();

            $stats[] = Stat::make('Đề nghị chi của tôi đang duyệt', (string) $pending)
                ->icon(Heroicon::OutlinedBanknotes)
                ->description('Đã gửi, chưa có kết quả duyệt')
                ->color('gray')
                ->url(ExpenseResource::getUrl('index', ['tab' => 'mine']));
        }

        return $stats;
    }

    /**
     * "+12,5% so với tháng trước".
     */
    private function change(string $current, string $previous): string
    {
        if (! Decimal::isPositive($previous)) {
            return Decimal::isPositive($current) ? 'Tháng trước chưa có số liệu' : 'Chưa có số liệu';
        }

        $change = Decimal::ratio(Decimal::sub($current, $previous), $previous, 1);
        $sign = (float) $change > 0 ? '+' : '';

        return $sign.Money::percent($change).' so với tháng trước';
    }

    private function changeIcon(string $current, string $previous): ?Heroicon
    {
        if (! Decimal::isPositive($previous)) {
            return null;
        }

        return match (Decimal::compare($current, $previous)) {
            1 => Heroicon::ArrowTrendingUp,
            -1 => Heroicon::ArrowTrendingDown,
            default => null,
        };
    }

    private function changeColor(string $current, string $previous, bool $higherIsBetter): string
    {
        if (! Decimal::isPositive($previous)) {
            return 'gray';
        }

        $direction = Decimal::compare($current, $previous);

        if ($direction === 0) {
            return 'gray';
        }

        return ($direction > 0) === $higherIsBetter ? 'success' : 'danger';
    }
}
