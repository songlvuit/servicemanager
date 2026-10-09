<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

class QuotationCalculationTest extends TestCase
{
    use BuildsQuotations;
    use RefreshDatabase;

    public function test_quotation_gets_a_code_and_starts_as_a_draft(): void
    {
        $this->travelTo('2026-05-10 09:00:00');

        $quotation = $this->makeQuotation();

        $this->assertSame('QT-2026-0001', $quotation->code);
        $this->assertTrue($quotation->isEditable());
        $this->assertSame('VND', $quotation->currency);
    }

    public function test_item_subtotal_is_calculated_when_saved(): void
    {
        $quotation = $this->makeQuotation([
            ['quantity' => 2, 'unit_price' => 10000000, 'discount' => 1000000],
        ]);

        $item = $quotation->items()->first();

        $this->assertSame('19000000.00', $item->subtotal);

        $item->update(['quantity' => 3]);

        $this->assertSame('29000000.00', $item->fresh()->subtotal);
    }

    public function test_totals_are_stored_on_the_quotation(): void
    {
        $quotation = $this->makeQuotation([
            ['quantity' => 2, 'unit_price' => 10000000, 'discount' => 1000000, 'tax_rate' => 10, 'estimated_cost' => 6000000],
            ['quantity' => 1, 'unit_price' => 5000000, 'discount' => 0, 'tax_rate' => 8, 'estimated_cost' => 2000000],
        ])->fresh();

        $this->assertSame('24000000.00', $quotation->subtotal);
        $this->assertSame('1000000.00', $quotation->discount);
        $this->assertSame('2300000.00', $quotation->tax);
        $this->assertSame('26300000.00', $quotation->total);
        $this->assertSame('14000000.00', $quotation->estimated_cost);
        $this->assertSame('10000000.00', $quotation->estimated_profit);
        $this->assertSame('41.67', $quotation->estimated_margin);
        $this->assertSame('25000000.00', $quotation->grossAmount());
        $this->assertSame('4.00', $quotation->discountPercent());
        $this->assertFalse($quotation->hasLowMargin());
        $this->assertFalse($quotation->exceedsDirectorDiscountThreshold());
    }

    public function test_low_margin_and_high_discount_are_flagged(): void
    {
        // Giá bán 10tr, chiết khấu 2,5tr (25%), giá vốn 7tr → biên lợi nhuận 6,67%.
        $quotation = $this->makeQuotation([
            ['unit_price' => 10000000, 'discount' => 2500000, 'estimated_cost' => 7000000],
        ])->fresh();

        $this->assertSame('6.67', $quotation->estimated_margin);
        $this->assertTrue($quotation->hasLowMargin());
        $this->assertSame('25.00', $quotation->discountPercent());
        $this->assertTrue($quotation->exceedsDirectorDiscountThreshold());
    }

    public function test_thresholds_come_from_configuration(): void
    {
        $quotation = $this->makeQuotation([
            ['unit_price' => 10000000, 'discount' => 1500000, 'estimated_cost' => 6000000],
        ])->fresh();

        // Chiết khấu 15%, biên lợi nhuận 29,41%.
        $this->assertFalse($quotation->exceedsDirectorDiscountThreshold());
        $this->assertFalse($quotation->hasLowMargin());

        config(['quotation.director_discount_threshold' => 10, 'quotation.low_margin_threshold' => 30]);

        $this->assertTrue($quotation->exceedsDirectorDiscountThreshold());
        $this->assertTrue($quotation->hasLowMargin());
    }

    public function test_profit_base_can_be_switched_to_total_including_tax(): void
    {
        config(['quotation.profit_base' => 'total']);

        $quotation = $this->makeQuotation([
            ['unit_price' => 10000000, 'tax_rate' => 10, 'estimated_cost' => 8000000],
        ])->fresh();

        // (11.000.000 − 8.000.000) / 11.000.000
        $this->assertSame('3000000.00', $quotation->estimated_profit);
        $this->assertSame('27.27', $quotation->estimated_margin);
    }

    public function test_cost_defaults_to_the_service_cost_when_the_form_does_not_send_it(): void
    {
        $service = Service::factory()->create(['default_cost' => 4500000]);
        $otherService = Service::factory()->create(['default_cost' => 7000000]);

        $quotation = $this->makeQuotation([]);

        // Người không có quyền xem giá vốn: form không gửi estimated_cost.
        $item = $quotation->items()->create([
            'service_id' => $service->getKey(),
            'description' => 'Chụp ảnh',
            'quantity' => 2,
            'unit_price' => 8000000,
        ]);

        $this->assertSame('4500000.00', $item->fresh()->estimated_cost);

        // Đổi dịch vụ mà không gửi giá vốn → lấy lại giá vốn của dịch vụ mới.
        $item->update(['service_id' => $otherService->getKey()]);

        $this->assertSame('7000000.00', $item->fresh()->estimated_cost);

        // Người có quyền nhập giá vốn riêng thì giữ nguyên số đã nhập.
        $item->update(['estimated_cost' => 5000000]);

        $this->assertSame('5000000.00', $item->fresh()->estimated_cost);
    }

    public function test_item_without_a_service_and_without_cost_has_zero_cost(): void
    {
        $quotation = $this->makeQuotation([]);

        $item = $quotation->items()->create([
            'description' => 'Hạng mục tự nhập',
            'quantity' => 1,
            'unit_price' => 1000000,
        ]);

        $this->assertSame('0.00', $item->fresh()->estimated_cost);
        $this->assertSame('1000000.00', $item->fresh()->subtotal);
    }
}
