<?php

namespace Database\Factories;

use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationItem>
 */
class QuotationItemFactory extends Factory
{
    /**
     * Mặc định: 1 × 10.000.000, không chiết khấu, VAT 10%, giá vốn 6.000.000
     * (biên lợi nhuận 40%).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'description' => 'Hạng mục '.fake()->unique()->numberBetween(1, 99999),
            'quantity' => 1,
            'unit' => 'gói',
            'unit_price' => 10000000,
            'discount' => 0,
            'tax_rate' => 10,
            'estimated_cost' => 6000000,
        ];
    }
}
