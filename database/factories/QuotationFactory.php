<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo báo giá NHÁP chưa có hạng mục. Thêm hạng mục bằng
 * QuotationItem::factory()->for($quotation) rồi gọi QuotationWorkflow để tính
 * tổng và chuyển trạng thái — không gán trạng thái trực tiếp.
 *
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => 'Báo giá '.fake()->unique()->numberBetween(1, 99999),
            'quotation_date' => today(),
            'valid_until' => today()->addDays(30),
        ];
    }
}
