<?php

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * View gộp mọi khoản CHI PHÍ THỰC TẾ (trước thuế) thành từng dòng có ngày,
     * dùng cho báo cáo chi phí và biểu đồ theo tháng. Định nghĩa "thực tế"
     * phải khớp với App\Services\Projects\ProjectCostCollector:
     *
     *   Đề nghị chi    đã thanh toán / hoàn tất     ngày chi
     *   Đơn mua hàng   đã nhận hàng / hoàn tất      ngày nhận hàng
     *   Chấm công      giờ × đơn giá                ngày làm việc
     *
     * (tests/Feature/FinancialReportTest kiểm tra hai nơi luôn cho cùng một tổng.)
     */
    public function up(): void
    {
        $list = fn (array $statuses): string => implode(', ', array_map(
            fn ($status): string => "'".$status->value."'",
            $statuses,
        ));

        $expenseStatuses = $list(array_filter(ExpenseStatus::cases(), fn (ExpenseStatus $status): bool => $status->isActual()));
        $orderStatuses = $list(array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $status): bool => $status->isActual()));
        $personnel = BudgetCategory::Personnel->value;

        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

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

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS project_cost_lines');
    }
};
