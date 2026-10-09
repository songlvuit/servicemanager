<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Quotations\QuotationWorkflow;

/**
 * Dựng nhanh báo giá nháp có hạng mục cho các bài test.
 */
trait BuildsQuotations
{
    /**
     * Mỗi hạng mục là mảng ghi đè lên mặc định:
     * 1 × 10.000.000, chiết khấu 0, VAT 10%, giá vốn 6.000.000.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $attributes
     */
    protected function makeQuotation(array $items = [[]], ?User $creator = null, array $attributes = []): Quotation
    {
        $quotation = new Quotation([
            'customer_id' => $attributes['customer_id'] ?? Customer::factory()->create()->getKey(),
            'opportunity_id' => $attributes['opportunity_id'] ?? null,
            'title' => $attributes['title'] ?? 'Báo giá thử nghiệm',
            'quotation_date' => $attributes['quotation_date'] ?? today(),
            'valid_until' => $attributes['valid_until'] ?? today()->addDays(30),
        ]);
        $quotation->created_by = $creator?->getKey();
        $quotation->save();

        foreach ($items as $index => $item) {
            $quotation->items()->create([
                'description' => 'Hạng mục '.($index + 1),
                'quantity' => 1,
                'unit' => 'gói',
                'unit_price' => 10000000,
                'discount' => 0,
                'tax_rate' => 10,
                'estimated_cost' => 6000000,
                'sort_order' => $index + 1,
                ...$item,
            ]);
        }

        return $this->workflow()->recalculate($quotation);
    }

    protected function workflow(): QuotationWorkflow
    {
        return app(QuotationWorkflow::class);
    }
}
