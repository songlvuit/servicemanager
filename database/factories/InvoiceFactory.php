<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo hóa đơn NHÁP 10.000.000 + 10% thuế = 11.000.000, hạn thanh toán sau 30
 * ngày, thuộc một hợp đồng đang hiệu lực. Phát hành bằng InvoiceWorkflow.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory()->active(),
            'customer_id' => fn (array $attributes): int => Contract::query()->findOrFail($attributes['contract_id'])->customer_id,
            'description' => 'Hóa đơn '.fake()->unique()->numberBetween(1, 99999),
            'invoice_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 10000000,
            'tax_rate' => 10,
        ];
    }
}
