<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định tạo hợp đồng NHÁP trị giá 100 triệu + 10 triệu thuế.
 *
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'title' => 'Hợp đồng '.fake()->unique()->numberBetween(1, 99999),
            'contract_value' => 100000000,
            'tax_amount' => 10000000,
            'start_date' => today(),
            'end_date' => today()->addMonths(3),
        ];
    }

    /**
     * Hợp đồng đã ký, đang hiệu lực (điều kiện để lập nghiệm thu, hóa đơn).
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ContractStatus::Active,
            'sign_date' => today(),
            'activated_at' => now(),
        ]);
    }

    public function pendingSignature(): static
    {
        return $this->state(fn (): array => ['status' => ContractStatus::PendingSignature]);
    }
}
