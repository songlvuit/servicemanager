<?php

namespace App\Services\Reports;

use App\Models\CostLine;
use App\Models\Invoice;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Doanh thu – chi phí – lợi nhuận của cả công ty theo thời gian (mục 35, 62).
 * Mọi số đều TRƯỚC THUẾ:
 *
 *   Doanh thu  = Σ giá trị trước thuế của hóa đơn đã phát hành, theo ngày hóa đơn
 *   Chi phí    = Σ chi phí thực tế (đề nghị chi đã thanh toán, đơn mua hàng đã
 *                nhận, công của nhân sự), theo ngày phát sinh — xem model CostLine
 *   Lợi nhuận  = Doanh thu − Chi phí
 *   Biên LN    = Lợi nhuận / Doanh thu × 100
 *
 * Số liệu được gom theo tháng bằng PHP (không dùng hàm ngày tháng riêng của
 * từng hệ quản trị cơ sở dữ liệu).
 */
class FinancialReport
{
    /**
     * Số liệu của $months tháng gần nhất, tính tới tháng chứa $until (mặc định: tháng này).
     *
     * @return list<array{key: string, label: string, revenue: string, cost: string, profit: string, margin: string}>
     */
    public function monthly(int $months = 12, ?CarbonInterface $until = null): array
    {
        $months = max(1, min($months, 60));
        $end = CarbonImmutable::parse($until ?? today())->endOfMonth();
        $start = $end->startOfMonth()->subMonthsNoOverflow($months - 1);

        $revenue = $this->revenueByMonth($start, $end);
        $cost = $this->costByMonth($start, $end);

        $rows = [];

        for ($month = $start; $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $monthRevenue = $revenue[$key] ?? '0.00';
            $monthCost = $cost[$key] ?? '0.00';
            $profit = Decimal::sub($monthRevenue, $monthCost);

            $rows[] = [
                'key' => $key,
                'label' => 'T'.$month->format('n/y'),
                'revenue' => Decimal::round($monthRevenue),
                'cost' => Decimal::round($monthCost),
                'profit' => Decimal::round($profit),
                'margin' => Decimal::ratio($profit, $monthRevenue),
            ];
        }

        return $rows;
    }

    /**
     * Tổng của một khoảng ngày (tính cả hai đầu).
     *
     * @return array{revenue: string, cost: string, profit: string, margin: string}
     */
    public function period(CarbonInterface $from, CarbonInterface $to): array
    {
        $revenue = Decimal::round(Invoice::query()
            ->billed()
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->sum('subtotal'));

        $cost = Decimal::round(CostLine::query()
            ->whereDate('cost_date', '>=', $from->toDateString())
            ->whereDate('cost_date', '<=', $to->toDateString())
            ->sum('amount'));

        $profit = Decimal::sub($revenue, $cost);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => Decimal::round($profit),
            'margin' => Decimal::ratio($profit, $revenue),
        ];
    }

    /**
     * @return array{revenue: string, cost: string, profit: string, margin: string}
     */
    public function month(?CarbonInterface $date = null): array
    {
        $date = CarbonImmutable::parse($date ?? today());

        return $this->period($date->startOfMonth(), $date->endOfMonth());
    }

    /**
     * @return array<string, string> khoá "Y-m"
     */
    private function revenueByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Invoice::query()
            ->billed()
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->toBase()
            ->get(['invoice_date', 'subtotal']);

        return $this->sumByMonth($rows, 'invoice_date', 'subtotal');
    }

    /**
     * @return array<string, string> khoá "Y-m"
     */
    private function costByMonth(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = CostLine::query()
            ->whereDate('cost_date', '>=', $from->toDateString())
            ->whereDate('cost_date', '<=', $to->toDateString())
            ->toBase()
            ->get(['cost_date', 'amount']);

        return $this->sumByMonth($rows, 'cost_date', 'amount');
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, string>
     */
    private function sumByMonth(iterable $rows, string $dateColumn, string $amountColumn): array
    {
        $totals = [];

        foreach ($rows as $row) {
            // Cột ngày có dạng "YYYY-MM-DD" hoặc "YYYY-MM-DD HH:MM:SS".
            $key = substr((string) $row->{$dateColumn}, 0, 7);

            $totals[$key] = Decimal::add($totals[$key] ?? '0', is_numeric($row->{$amountColumn}) ? (string) $row->{$amountColumn} : '0');
        }

        return $totals;
    }
}
