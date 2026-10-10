<?php

namespace Database\Factories;

use App\Enums\BudgetCategory;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo yêu cầu mua hàng NHÁP trị giá 10.000.000, nhóm "Thiết bị".
 *
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'category' => BudgetCategory::Equipment,
            'description' => 'Yêu cầu mua '.fake()->unique()->numberBetween(1, 99999),
            'amount' => 10000000,
        ];
    }
}
