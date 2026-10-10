<?php

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mỗi lịch làm việc gắn với một CÔNG VIỆC, và công việc là nơi quản lý tiền:
 *
 *   - tasks: ngân sách, chi phí đã cam kết, chi phí thực tế;
 *   - expenses / purchase_requests / purchase_orders: gắn được vào một công việc
 *     (timesheets đã có task_id từ trước);
 *   - schedule_entries.task_id: công việc của lịch;
 *   - view project_cost_lines có thêm cột task_id.
 *
 * Lịch đã có từ trước được tạo công việc tương ứng ở migration kế tiếp.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['expenses', 'purchase_requests', 'purchase_orders'];

    public function up(): void
    {
        // View đọc các bảng sắp sửa nên phải gỡ ra trước rồi dựng lại.
        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('budget', 15, 2)->default(0);
            $table->decimal('committed_cost', 15, 2)->default(0);
            $table->decimal('actual_cost', 15, 2)->default(0);
            // Công việc do lịch làm việc tự tạo: tên, ngày, trạng thái đi theo lịch.
            $table->boolean('from_schedule')->default(false);
        });

        foreach ([...self::DOCUMENTS, 'schedule_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->index('task_id');
            });
        }

        $this->createCostLinesView(withTask: true);

        // Việc tạo bù công việc cho lịch cũ nằm ở migration 2026_07_04_000000,
        // sau khi bảng tasks có đủ các cột thông tin lịch.
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

        foreach ([...self::DOCUMENTS, 'schedule_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('task_id');
            });
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['budget', 'committed_cost', 'actual_cost', 'from_schedule']);
        });

        $this->createCostLinesView(withTask: false);
    }

    /**
     * Cùng định nghĩa với migration 2026_07_02_000000, thêm cột task_id.
     */
    private function createCostLinesView(bool $withTask): void
    {
        $list = fn (array $statuses): string => implode(', ', array_map(
            fn ($status): string => "'".$status->value."'",
            $statuses,
        ));

        $expenseStatuses = $list(array_filter(ExpenseStatus::cases(), fn (ExpenseStatus $status): bool => $status->isActual()));
        $orderStatuses = $list(array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $status): bool => $status->isActual()));
        $personnel = BudgetCategory::Personnel->value;

        $task = fn (string $alias): string => $withTask ? "{$alias}.task_id AS task_id," : '';

        DB::statement(<<<SQL
            CREATE VIEW project_cost_lines AS
            SELECT
                'expense-' || e.id AS id,
                'expense' AS source,
                e.id AS source_id,
                e.code AS reference,
                e.project_id AS project_id,
                {$task('e')}
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
                {$task('p')}
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
                {$task('t')}
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
