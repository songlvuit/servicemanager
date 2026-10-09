<?php

namespace Database\Factories;

use App\Enums\BudgetCategory;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tạo đơn mua hàng NHÁP chưa có dòng hàng, nhóm "Thiết bị". Thêm dòng bằng
 * $order->items()->create(...) rồi gọi PurchaseOrderWorkflow.
 *
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'project_id' => Project::factory(),
            'category' => BudgetCategory::Equipment,
            'order_date' => today(),
        ];
    }
}
