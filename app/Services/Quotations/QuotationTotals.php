<?php

namespace App\Services\Quotations;

use App\Support\Decimal;

/**
 * Kết quả tính toán của một báo giá. Mọi số tiền là chuỗi DECIMAL 2 chữ số.
 */
final readonly class QuotationTotals
{
    public function __construct(
        public string $gross,            // Σ số lượng × đơn giá (trước chiết khấu)
        public string $discount,         // Σ chiết khấu
        public string $subtotal,         // gross − discount (trước thuế)
        public string $tax,              // Σ thuế từng hạng mục
        public string $total,            // subtotal + tax
        public string $estimatedCost,    // Σ số lượng × giá vốn
        public string $estimatedProfit,
        public string $estimatedMargin,  // %
        public string $discountPercent,  // discount / gross × 100
    ) {}

    public function isLowMargin(int|float|string $threshold): bool
    {
        return Decimal::compare($this->estimatedMargin, $threshold) < 0;
    }

    public function exceedsDiscount(int|float|string $threshold): bool
    {
        return Decimal::compare($this->discountPercent, $threshold) > 0;
    }
}
