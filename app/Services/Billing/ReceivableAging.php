<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tuổi nợ phải thu (mục 32): chia số còn phải thu của các hóa đơn đang mở
 * theo số ngày quá hạn tính tới hôm nay.
 */
class ReceivableAging
{
    /**
     * Khoá => [nhãn, số ngày quá hạn từ, tới (null = không giới hạn)].
     */
    public const BUCKETS = [
        'current' => ['Chưa tới hạn', null, 0],
        '1_30' => ['Quá hạn 1–30 ngày', 1, 30],
        '31_60' => ['Quá hạn 31–60 ngày', 31, 60],
        '61_90' => ['Quá hạn 61–90 ngày', 61, 90],
        'over_90' => ['Quá hạn trên 90 ngày', 91, null],
    ];

    public static function label(string $bucket): string
    {
        return self::BUCKETS[$bucket][0] ?? $bucket;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $bucket): string => $bucket[0], self::BUCKETS);
    }

    public function bucketFor(Invoice $invoice): string
    {
        return $this->bucketForDays($invoice->daysOverdue());
    }

    public function bucketForDays(int $daysOverdue): string
    {
        foreach (self::BUCKETS as $key => [, $from, $to]) {
            if (($from === null || $daysOverdue >= $from) && ($to === null || $daysOverdue <= $to)) {
                return $key;
            }
        }

        return 'current';
    }

    /**
     * Giới hạn truy vấn hóa đơn vào một nhóm tuổi nợ.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeBucket(Builder $query, string $bucket): Builder
    {
        [, $from, $to] = self::BUCKETS[$bucket] ?? self::BUCKETS['current'];

        // Quá hạn N ngày ⇔ due_date = hôm nay − N.
        if ($from !== null) {
            $query->whereDate('due_date', '<=', today()->subDays($from));
        }

        if ($to !== null) {
            $query->whereDate('due_date', '>=', today()->subDays($to));
        }

        return $query;
    }

    /**
     * Tổng còn phải thu và số hóa đơn của từng nhóm tuổi nợ.
     *
     * @param  Builder<Invoice>|null  $query  Mặc định: mọi hóa đơn đang mở.
     * @return array{total: string, count: int, buckets: array<string, array{label: string, amount: string, count: int}>}
     */
    public function summary(?Builder $query = null): array
    {
        $buckets = [];

        foreach (self::BUCKETS as $key => [$label]) {
            $buckets[$key] = ['label' => $label, 'amount' => '0.00', 'count' => 0];
        }

        $total = '0.00';
        $count = 0;

        ($query ?? Invoice::query())
            ->open()
            ->get(['id', 'due_date', 'status', 'remaining_amount'])
            ->each(function (Invoice $invoice) use (&$buckets, &$total, &$count): void {
                $key = $this->bucketFor($invoice);

                $buckets[$key]['amount'] = Decimal::add($buckets[$key]['amount'], $invoice->remaining_amount);
                $buckets[$key]['count']++;

                $total = Decimal::add($total, $invoice->remaining_amount);
                $count++;
            });

        return ['total' => $total, 'count' => $count, 'buckets' => $buckets];
    }
}
