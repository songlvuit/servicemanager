<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chỉ dùng để dựng dữ liệu hiển thị; trong nghiệp vụ, thanh toán luôn được tạo
 * qua PaymentService để hóa đơn được cập nhật cùng lúc.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'customer_id' => fn (array $attributes): int => Invoice::query()->findOrFail($attributes['invoice_id'])->customer_id,
            'payment_date' => today(),
            'amount' => 1000000,
            'payment_method' => PaymentMethod::BankTransfer,
        ];
    }
}
