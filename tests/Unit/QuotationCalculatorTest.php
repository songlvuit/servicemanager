<?php

namespace Tests\Unit;

use App\Services\Quotations\QuotationCalculator;
use PHPUnit\Framework\TestCase;

class QuotationCalculatorTest extends TestCase
{
    /**
     * @return list<array<string, string>>
     */
    private function sampleItems(): array
    {
        return [
            ['quantity' => '2', 'unit_price' => '10000000', 'discount' => '1000000', 'tax_rate' => '10', 'estimated_cost' => '6000000'],
            ['quantity' => '1', 'unit_price' => '5000000', 'discount' => '0', 'tax_rate' => '8', 'estimated_cost' => '2000000'],
        ];
    }

    public function test_line_subtotal_is_quantity_times_price_minus_discount(): void
    {
        $calculator = new QuotationCalculator;

        $this->assertSame('19000000.00', $calculator->lineSubtotal($this->sampleItems()[0]));
    }

    public function test_it_calculates_quotation_totals(): void
    {
        $totals = (new QuotationCalculator)->calculate($this->sampleItems());

        $this->assertSame('25000000.00', $totals->gross);
        $this->assertSame('1000000.00', $totals->discount);
        $this->assertSame('24000000.00', $totals->subtotal);
        // 19.000.000 × 10% + 5.000.000 × 8%
        $this->assertSame('2300000.00', $totals->tax);
        $this->assertSame('26300000.00', $totals->total);
        $this->assertSame('4.00', $totals->discountPercent);
    }

    public function test_profit_and_margin_use_the_pre_tax_subtotal_by_default(): void
    {
        $totals = (new QuotationCalculator)->calculate($this->sampleItems());

        // Giá vốn: 2 × 6.000.000 + 1 × 2.000.000
        $this->assertSame('14000000.00', $totals->estimatedCost);
        $this->assertSame('10000000.00', $totals->estimatedProfit);
        $this->assertSame('41.67', $totals->estimatedMargin);
    }

    public function test_profit_can_be_based_on_the_total_including_tax(): void
    {
        $item = [['quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_rate' => 10, 'estimated_cost' => 80]];

        $onSubtotal = (new QuotationCalculator(QuotationCalculator::BASE_SUBTOTAL))->calculate($item);
        $onTotal = (new QuotationCalculator(QuotationCalculator::BASE_TOTAL))->calculate($item);

        $this->assertSame('20.00', $onSubtotal->estimatedProfit);
        $this->assertSame('20.00', $onSubtotal->estimatedMargin);
        $this->assertSame('30.00', $onTotal->estimatedProfit);
        $this->assertSame('27.27', $onTotal->estimatedMargin);
    }

    public function test_low_margin_warning_triggers_below_the_threshold_only(): void
    {
        $atThreshold = (new QuotationCalculator)->calculate([
            ['quantity' => 1, 'unit_price' => 100, 'estimated_cost' => 80],
        ]);
        $below = (new QuotationCalculator)->calculate([
            ['quantity' => 1, 'unit_price' => 100, 'estimated_cost' => 81],
        ]);

        $this->assertFalse($atThreshold->isLowMargin(20));
        $this->assertTrue($below->isLowMargin(20));
    }

    public function test_discount_above_the_threshold_requires_director_approval(): void
    {
        $calculator = new QuotationCalculator;

        $exactly20 = $calculator->calculate([['quantity' => 1, 'unit_price' => 1000, 'discount' => 200]]);
        $above20 = $calculator->calculate([['quantity' => 1, 'unit_price' => 1000, 'discount' => 250]]);

        $this->assertSame('20.00', $exactly20->discountPercent);
        $this->assertFalse($exactly20->exceedsDiscount(20));
        $this->assertSame('25.00', $above20->discountPercent);
        $this->assertTrue($above20->exceedsDiscount(20));
    }

    public function test_it_tolerates_half_filled_form_rows(): void
    {
        $totals = (new QuotationCalculator)->calculate([
            'uuid-1' => ['quantity' => '', 'unit_price' => null, 'discount' => 'abc'],
            'uuid-2' => ['quantity' => '1.5', 'unit_price' => '333.33', 'tax_rate' => 8],
        ]);

        $this->assertSame('500.00', $totals->gross);
        $this->assertSame('40.00', $totals->tax);
        $this->assertSame('540.00', $totals->total);
    }

    public function test_an_empty_quotation_is_all_zero(): void
    {
        $totals = (new QuotationCalculator)->calculate([]);

        $this->assertSame('0.00', $totals->total);
        $this->assertSame('0.00', $totals->estimatedMargin);
        $this->assertSame('0.00', $totals->discountPercent);
    }

    public function test_a_loss_gives_a_negative_margin(): void
    {
        $totals = (new QuotationCalculator)->calculate([
            ['quantity' => 1, 'unit_price' => 100, 'estimated_cost' => 150],
        ]);

        $this->assertSame('-50.00', $totals->estimatedProfit);
        $this->assertSame('-50.00', $totals->estimatedMargin);
        $this->assertTrue($totals->isLowMargin(20));
    }
}
