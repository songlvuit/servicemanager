<?php

namespace App\Services\Quotations;

use App\Support\Decimal;
use ArrayAccess;

/**
 * Công thức tính báo giá (mục 12 của yêu cầu). Lớp này không đụng tới
 * database: nhận danh sách hạng mục (model hoặc mảng dữ liệu form) và trả về
 * các con số, nên dùng được cả khi lưu lẫn khi xem trước trên form.
 *
 *   Hạng mục:  subtotal = quantity × unit_price − discount
 *              thuế     = subtotal × tax_rate / 100
 *              giá vốn  = quantity × estimated_cost (giá vốn một đơn vị)
 *   Báo giá:   total    = Σ subtotal + Σ thuế
 *              lợi nhuận ước tính = doanh thu − Σ giá vốn
 *              biên lợi nhuận     = lợi nhuận / doanh thu × 100
 *
 * "Doanh thu" là giá trị trước thuế (mặc định) hoặc sau thuế, tuỳ
 * config('quotation.profit_base').
 */
class QuotationCalculator
{
    public const BASE_SUBTOTAL = 'subtotal';

    public const BASE_TOTAL = 'total';

    public function __construct(
        private readonly string $profitBase = self::BASE_SUBTOTAL,
    ) {}

    /**
     * Thành tiền của một hạng mục: quantity × unit_price − discount.
     */
    public function lineSubtotal(mixed $item): string
    {
        return Decimal::round(Decimal::sub($this->lineGross($item), Decimal::round($this->value($item, 'discount'))));
    }

    /**
     * @param  iterable<mixed>  $items
     */
    public function calculate(iterable $items): QuotationTotals
    {
        $gross = '0';
        $discount = '0';
        $tax = '0';
        $cost = '0';

        foreach ($items as $item) {
            $lineGross = $this->lineGross($item);
            $lineDiscount = Decimal::round($this->value($item, 'discount'));
            $lineSubtotal = Decimal::sub($lineGross, $lineDiscount);

            $gross = Decimal::add($gross, $lineGross);
            $discount = Decimal::add($discount, $lineDiscount);
            $tax = Decimal::add($tax, Decimal::percentOf($lineSubtotal, $this->value($item, 'tax_rate')));
            $cost = Decimal::add($cost, Decimal::mul($this->value($item, 'quantity'), $this->value($item, 'estimated_cost')));
        }

        $subtotal = Decimal::sub($gross, $discount);
        $total = Decimal::add($subtotal, $tax);

        $revenue = $this->profitBase === self::BASE_TOTAL ? $total : $subtotal;
        $profit = Decimal::sub($revenue, $cost);

        return new QuotationTotals(
            gross: Decimal::round($gross),
            discount: Decimal::round($discount),
            subtotal: Decimal::round($subtotal),
            tax: Decimal::round($tax),
            total: Decimal::round($total),
            estimatedCost: Decimal::round($cost),
            estimatedProfit: Decimal::round($profit),
            estimatedMargin: Decimal::ratio($profit, $revenue),
            discountPercent: Decimal::ratio($discount, $gross),
        );
    }

    private function lineGross(mixed $item): string
    {
        return Decimal::mul($this->value($item, 'quantity'), $this->value($item, 'unit_price'));
    }

    /**
     * Đọc một trường từ model hoặc mảng; thiếu / rỗng / không phải số coi là 0.
     */
    private function value(mixed $item, string $key): string
    {
        $raw = null;

        if (is_array($item) || $item instanceof ArrayAccess) {
            $raw = $item[$key] ?? null;
        } elseif (is_object($item)) {
            $raw = $item->{$key} ?? null;
        }

        if (! is_int($raw) && ! is_float($raw) && ! is_string($raw)) {
            return '0';
        }

        return (string) Decimal::of($raw);
    }
}
