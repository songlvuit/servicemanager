<?php

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * View `task_cost_items`: MỌI khoản chi phí của công việc trên một danh sách —
 * đề nghị chi, yêu cầu mua hàng, đơn mua hàng và công của nhân sự — theo đúng
 * quy tắc "đã cam kết / thực tế" của TaskCostCollector:
 *
 *   Σ amount                    = chi phí đã cam kết
 *   Σ amount WHERE is_actual=1  = chi phí thực tế
 *
 * (View `cost_lines` chỉ gồm phần thực tế và dùng cho báo cáo tài chính theo
 * tháng; view này dùng cho tab "Chi phí" của công việc và trang "Chi phí theo
 * công việc".)
 *
 * Kế toán được xuất bảng công (tính lương theo buổi).
 */
return new class extends Migration
{
    public function up(): void
    {
        $list = fn (array $statuses): string => implode(', ', array_map(
            fn ($status): string => "'".$status->value."'",
            array_values($statuses),
        ));

        $expenseCommitted = $list(array_filter(ExpenseStatus::cases(), fn (ExpenseStatus $status): bool => $status->isCommitted()));
        $expenseActual = $list(array_filter(ExpenseStatus::cases(), fn (ExpenseStatus $status): bool => $status->isActual()));
        $orderCommitted = $list(array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $status): bool => $status->isCommitted()));
        $orderActual = $list(array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $status): bool => $status->isActual()));
        $requestApproved = PurchaseRequestStatus::Approved->value;
        $personnel = BudgetCategory::Personnel->value;

        DB::statement('DROP VIEW IF EXISTS task_cost_items');

        DB::statement(<<<SQL
            CREATE VIEW task_cost_items AS
            SELECT
                'expense-' || e.id AS id,
                'expense' AS source,
                e.id AS source_id,
                e.code AS reference,
                e.task_id AS task_id,
                e.category AS category,
                e.vendor_id AS supplier_id,
                e.employee_id AS user_id,
                e.expense_date AS cost_date,
                e.description AS description,
                CAST(NULL AS DECIMAL(8, 2)) AS sessions,
                CAST(NULL AS DECIMAL(15, 2)) AS unit_price,
                e.amount AS amount,
                CASE WHEN e.status IN ({$expenseActual}) THEN 1 ELSE 0 END AS is_actual
            FROM expenses e
            WHERE e.deleted_at IS NULL AND e.status IN ({$expenseCommitted})
            UNION ALL
            SELECT
                'purchase_request-' || r.id,
                'purchase_request',
                r.id,
                r.code,
                r.task_id,
                r.category,
                r.supplier_id,
                r.requester_id,
                COALESCE(r.needed_date, r.created_at),
                r.description,
                CAST(NULL AS DECIMAL(8, 2)),
                CAST(NULL AS DECIMAL(15, 2)),
                r.amount,
                0
            FROM purchase_requests r
            WHERE r.status = '{$requestApproved}'
            UNION ALL
            SELECT
                'purchase_order-' || p.id,
                'purchase_order',
                p.id,
                p.code,
                p.task_id,
                p.category,
                p.supplier_id,
                p.created_by,
                COALESCE(p.received_at, p.order_date),
                COALESCE(p.notes, (SELECT MIN(i.description) FROM purchase_order_items i WHERE i.purchase_order_id = p.id)),
                CAST(NULL AS DECIMAL(8, 2)),
                CAST(NULL AS DECIMAL(15, 2)),
                p.subtotal,
                CASE WHEN p.status IN ({$orderActual}) THEN 1 ELSE 0 END
            FROM purchase_orders p
            WHERE p.status IN ({$orderCommitted})
            UNION ALL
            SELECT
                'timesheet-' || t.id,
                'timesheet',
                t.id,
                NULL,
                t.task_id,
                '{$personnel}',
                NULL,
                t.user_id,
                t.work_date,
                t.description,
                t.sessions,
                t.session_rate,
                t.cost,
                1
            FROM timesheets t
            WHERE t.cost > 0
            SQL);

        $this->grantTimesheetExportToAccountant();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS task_cost_items');
    }

    private function grantTimesheetExportToAccountant(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['permissions'])) {
            return;
        }

        $permissionId = DB::table($tables['permissions'])->where('name', 'timesheets.export')->value('id');
        $roleId = DB::table($tables['roles'])->where('name', 'Accountant')->value('id');

        if ($permissionId !== null && $roleId !== null) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
