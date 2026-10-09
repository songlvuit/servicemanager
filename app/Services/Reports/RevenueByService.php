<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Doanh thu theo dịch vụ (mục 35).
 *
 * Hóa đơn không ghi từng dịch vụ, nên doanh thu trước thuế của mỗi hóa đơn
 * được CHIA theo tỷ trọng các hạng mục trong báo giá của hợp đồng tương ứng.
 * Hóa đơn của hợp đồng không gắn báo giá (hoặc hạng mục tự nhập, không chọn
 * dịch vụ) được xếp vào nhóm "Chưa phân loại".
 */
class RevenueByService
{
    public const UNCLASSIFIED = 'Chưa phân loại';

    public const OTHER = 'Dịch vụ khác';

    /**
     * Doanh thu từng dịch vụ trong khoảng ngày, giảm dần; chỉ giữ $limit dịch
     * vụ lớn nhất, phần còn lại gộp vào "Dịch vụ khác".
     *
     * @return array<string, string> tên dịch vụ => doanh thu
     */
    public function between(CarbonInterface $from, CarbonInterface $to, int $limit = 6): array
    {
        $totals = [];

        Invoice::query()
            ->billed()
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->with('contract.quotation.items.service')
            ->get()
            ->each(function (Invoice $invoice) use (&$totals): void {
                foreach ($this->allocate($invoice) as $service => $amount) {
                    $totals[$service] = Decimal::add($totals[$service] ?? '0', $amount);
                }
            });

        uasort($totals, fn (string $a, string $b): int => Decimal::compare($b, $a));

        if (count($totals) <= $limit) {
            return array_map(fn (string $amount): string => Decimal::round($amount), $totals);
        }

        $top = array_slice($totals, 0, $limit, true);
        $rest = '0';

        foreach (array_slice($totals, $limit, null, true) as $amount) {
            $rest = Decimal::add($rest, $amount);
        }

        $top[self::OTHER] = Decimal::add($top[self::OTHER] ?? '0', $rest);

        return array_map(fn (string $amount): string => Decimal::round($amount), $top);
    }

    /**
     * Chia doanh thu của một hóa đơn cho các dịch vụ trong báo giá gốc.
     *
     * @return array<string, string>
     */
    public function allocate(Invoice $invoice): array
    {
        $weights = [];

        foreach ($invoice->contract?->quotation?->items ?? [] as $item) {
            if (! Decimal::isPositive($item->subtotal)) {
                continue;
            }

            $service = $item->service?->name ?? self::UNCLASSIFIED;
            $weights[$service] = Decimal::add($weights[$service] ?? '0', $item->subtotal);
        }

        if ($weights === []) {
            return [self::UNCLASSIFIED => Decimal::round($invoice->subtotal)];
        }

        $totalWeight = array_reduce($weights, fn (string $carry, string $weight): string => Decimal::add($carry, $weight), '0');
        $shares = [];
        $allocated = '0';
        $last = array_key_last($weights);

        foreach ($weights as $service => $weight) {
            // Dịch vụ cuối nhận phần còn lại để tổng luôn bằng đúng doanh thu hóa đơn.
            $share = $service === $last
                ? Decimal::sub($invoice->subtotal, $allocated)
                : Decimal::div(Decimal::mul($invoice->subtotal, $weight, 6), $totalWeight);

            $shares[$service] = Decimal::round($share);
            $allocated = Decimal::add($allocated, $shares[$service]);
        }

        return $shares;
    }
}
