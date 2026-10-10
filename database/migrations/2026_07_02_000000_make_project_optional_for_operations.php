<?php

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Công việc, đề nghị chi, yêu cầu mua hàng, đơn mua hàng và giờ công
     * KHÔNG còn bắt buộc gắn với một dự án: phần lớn việc của công ty là việc
     * độc lập. Chi phí không gắn dự án là chi phí chung của công ty — vẫn
     * tính vào chi phí theo tháng, nhưng không vào lợi nhuận của dự án nào.
     */
    private const TABLES = ['tasks', 'expenses', 'purchase_requests', 'purchase_orders', 'timesheets'];

    public function up(): void
    {
        // View project_cost_lines đọc các bảng sắp sửa nên phải gỡ ra trước rồi dựng lại.
        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('project_id')->nullable()->change();
            });
        }

        $this->createCostLinesView();
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->whereNull('project_id')->exists()) {
                throw new RuntimeException("Không thể hoàn tác: bảng {$name} đang có bản ghi không gắn dự án.");
            }
        }

        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('project_id')->nullable(false)->change();
            });
        }

        $this->createCostLinesView();
    }

    /**
     * Cùng định nghĩa với migration 2026_06_01_000001 (dòng không gắn dự án có project_id rỗng).
     */
    private function createCostLinesView(): void
    {
        $list = fn (array $statuses): string => implode(', ', array_map(
            fn ($status): string => "'".$status->value."'",
            $statuses,
        ));

        $expenseStatuses = $list(array_filter(ExpenseStatus::cases(), fn (ExpenseStatus $status): bool => $status->isActual()));
        $orderStatuses = $list(array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $status): bool => $status->isActual()));
        $personnel = BudgetCategory::Personnel->value;

        DB::statement(<<<SQL
            CREATE VIEW project_cost_lines AS
            SELECT
                'expense-' || e.id AS id,
                'expense' AS source,
                e.id AS source_id,
                e.code AS reference,
                e.project_id AS project_id,
                e.category AS category,
                e.vendor_id AS supplier_id,
                e.expense_date AS cost_date,
                e.description AS description,
                e.amount AS amount
            FROM expenses e
            WHERE e.deleted_at IS NULL AND e.status IN ({$expenseStatuses})
            UNION ALL
            SELECT
                'purchase_order-' || p.id,
                'purchase_order',
                p.id,
                p.code,
                p.project_id,
                p.category,
                p.supplier_id,
                COALESCE(p.received_at, p.order_date),
                p.notes,
                p.subtotal
            FROM purchase_orders p
            WHERE p.status IN ({$orderStatuses})
            UNION ALL
            SELECT
                'timesheet-' || t.id,
                'timesheet',
                t.id,
                NULL,
                t.project_id,
                '{$personnel}',
                NULL,
                t.work_date,
                t.description,
                t.cost
            FROM timesheets t
            WHERE t.cost > 0
            SQL);
    }
};
