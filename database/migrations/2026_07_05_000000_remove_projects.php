<?php

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\User;
use App\Services\Contracts\ContractFinancials;
use App\Services\Scheduling\TaskSchedule;
use App\Services\Tasks\TaskFinancials;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * BỎ HẲN MODULE DỰ ÁN. Phần mềm chỉ còn quản lý các CÔNG VIỆC độc lập:
 *
 *   - công việc gắn thẳng với hợp đồng và báo giá (tasks.contract_id, quotation_id);
 *   - chi phí (đề nghị chi, mua hàng, giờ công) chỉ còn gắn với công việc;
 *   - nghiệm thu và hóa đơn gắn với hợp đồng; chi phí, lợi nhuận của hợp đồng
 *     là tổng từ các công việc của nó (các cột tiền mới trên bảng contracts);
 *   - bỏ các bảng projects, project_members, budget_items và mọi cột project_id;
 *   - view chi phí thực tế đổi tên thành cost_lines.
 *
 * Dữ liệu dự án đang có KHÔNG bị mất: mỗi dự án trở thành một công việc (mang
 * tên, người quản lý, ngày, ngân sách, hợp đồng của dự án); các công việc của
 * dự án thành công việc con của nó; chứng từ chi phí ghi thẳng vào dự án được
 * chuyển sang công việc đó; nghiệm thu và tài liệu của dự án chuyển về hợp đồng.
 *
 * Quyền: bỏ quyền của hai module Dự án và Ngân sách; nội dung hợp đồng, báo
 * giá, nghiệm thu, hóa đơn chỉ còn Giám đốc và Kế toán xem (đổi lại được ở
 * Hệ thống → Vai trò & quyền).
 */
return new class extends Migration
{
    /** Các bảng chứng từ có cột project_id cần bỏ. */
    private const WITH_PROJECT = ['tasks', 'expenses', 'purchase_requests', 'purchase_orders', 'timesheets', 'invoices', 'acceptances', 'schedule_entries'];

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS project_cost_lines');

        Schema::table('tasks', function (Blueprint $table): void {
            // Không đặt khoá ngoại: hợp đồng / báo giá chỉ xoá mềm.
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->index('contract_id');
            $table->index('quotation_id');
        });

        Schema::table('acceptances', function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->index('contract_id');
        });

        // Tiền của hợp đồng, do ContractFinancials tính từ các công việc và hóa đơn.
        Schema::table('contracts', function (Blueprint $table): void {
            $table->decimal('budget', 15, 2)->default(0);
            $table->decimal('committed_cost', 15, 2)->default(0);
            $table->decimal('actual_cost', 15, 2)->default(0);
            $table->decimal('actual_profit', 15, 2)->default(0);
            $table->decimal('profit_margin', 8, 2)->default(0);
            $table->decimal('invoiced_amount', 15, 2)->default(0);
            $table->decimal('collected_amount', 15, 2)->default(0);
        });

        $jobIds = $this->convertProjects();

        $this->dropProjectColumns();

        Schema::dropIfExists('project_members');
        Schema::dropIfExists('budget_items');
        Schema::dropIfExists('projects');

        $this->createCostLinesView();
        $this->renameSettings('project.', 'job.');

        // Bước duyệt mặc định mang tên vai trò cũ.
        DB::table('approval_rules')->where('name', 'Quản lý dự án duyệt')->update(['name' => 'Quản lý công việc duyệt']);
        $this->tightenPermissions();
        $this->pruneNotifications();

        $this->refreshTasks($jobIds);

        // Tính tiền cho các hợp đồng đang có (cài mới thì chưa có hợp đồng nào).
        app(ContractFinancials::class)->recalculateMany(DB::table('contracts')->pluck('id'));
    }

    /**
     * Thao tác này không đảo ngược được: dự án đã thành công việc.
     */
    public function down(): void
    {
        throw new RuntimeException('Không thể khôi phục module Dự án bằng migrate:rollback. Hãy khôi phục từ bản sao lưu database.');
    }

    /**
     * Mỗi dự án → một công việc; trả về id các công việc vừa tạo.
     *
     * @return list<int>
     */
    private function convertProjects(): array
    {
        $jobIds = [];
        $statuses = [
            'planning' => 'todo',
            'active' => 'in_progress',
            'on_hold' => 'todo',
            'paused' => 'todo',
            'completed' => 'done',
            'cancelled' => 'cancelled',
        ];

        foreach (DB::table('projects')->orderBy('id')->get() as $project) {
            $quotationId = DB::table('contracts')->where('id', $project->contract_id)->value('quotation_id');
            $status = $project->deleted_at !== null ? 'cancelled' : ($statuses[$project->status] ?? 'todo');

            $jobId = (int) DB::table('tasks')->insertGetId([
                'project_id' => null,
                'name' => $project->name,
                // Đường dẫn thư mục của dự án được giữ lại trong mô tả công việc.
                'description' => implode("\n\n", array_filter([
                    $project->description,
                    filled($project->drive_url ?? null) ? 'Thư mục lưu trữ: '.$project->drive_url : null,
                ])) ?: null,
                'assignee_id' => $project->project_manager_id,
                'priority' => 'medium',
                'status' => $status,
                'start_date' => $project->start_date,
                'due_date' => $project->end_date,
                'estimated_hours' => 0,
                'actual_hours' => 0,
                'progress' => $status === 'done' ? 100 : (int) $project->progress,
                'sort_order' => (int) DB::table('tasks')->where('status', $status)->max('sort_order') + 1,
                'budget' => $project->budget,
                'contract_id' => $project->contract_id,
                'quotation_id' => $quotationId,
                'customer_id' => $project->customer_id,
                'created_at' => $project->created_at,
                'updated_at' => now(),
            ]);

            // Công việc của dự án thành công việc con, và cùng hợp đồng với nó.
            DB::table('tasks')->where('project_id', $project->id)->whereNull('parent_id')->update(['parent_id' => $jobId]);
            DB::table('tasks')->where('project_id', $project->id)->update([
                'contract_id' => $project->contract_id,
                'quotation_id' => $quotationId,
            ]);
            DB::table('tasks')->where('project_id', $project->id)->whereNull('customer_id')->update(['customer_id' => $project->customer_id]);

            // Công việc sinh từ lịch của dự án (có thể chưa mang project_id) cũng về cùng hợp đồng.
            $scheduled = DB::table('schedule_entries')->where('project_id', $project->id)->whereNotNull('task_id')->pluck('task_id')->all();

            if ($scheduled !== []) {
                DB::table('tasks')->whereIn('id', $scheduled)->where('id', '!=', $jobId)->whereNull('contract_id')->update([
                    'contract_id' => $project->contract_id,
                    'quotation_id' => $quotationId,
                ]);
                DB::table('tasks')->whereIn('id', $scheduled)->where('id', '!=', $jobId)->whereNull('parent_id')->update(['parent_id' => $jobId]);
            }

            // Chứng từ ghi thẳng vào dự án (chưa gắn công việc nào) chuyển sang công việc của dự án.
            foreach (['expenses', 'purchase_requests', 'purchase_orders', 'timesheets'] as $table) {
                DB::table($table)->where('project_id', $project->id)->whereNull('task_id')->update(['task_id' => $jobId]);
            }

            DB::table('acceptances')->where('project_id', $project->id)->update(['contract_id' => $project->contract_id]);

            // Tài liệu đính kèm của dự án chuyển về hợp đồng.
            DB::table('attachments')
                ->where('attachable_type', 'App\\Models\\Project')
                ->where('attachable_id', $project->id)
                ->update(['attachable_type' => 'App\\Models\\Contract', 'attachable_id' => $project->contract_id]);

            $jobIds[] = $jobId;
        }

        return $jobIds;
    }

    private function dropProjectColumns(): void
    {
        foreach (self::WITH_PROJECT as $name) {
            // Bảng tasks có chỉ mục ghép theo dự án; các bảng khác có chỉ mục đơn.
            $index = $name === 'tasks' ? ['project_id', 'status', 'sort_order'] : ['project_id'];

            Schema::table($name, function (Blueprint $table) use ($index): void {
                $table->dropForeign(['project_id']);
                $table->dropIndex($index);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('project_id');
            });
        }

        // Thẻ Kanban nay xếp theo cột trạng thái của toàn bộ công việc.
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['status', 'sort_order']);
        });
    }

    /**
     * Chi phí thực tế (trước thuế) của công ty, từng dòng một: đề nghị chi đã
     * thanh toán, đơn mua hàng đã nhận hàng, giờ công.
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
            CREATE VIEW cost_lines AS
            SELECT
                'expense-' || e.id AS id,
                'expense' AS source,
                e.id AS source_id,
                e.code AS reference,
                e.task_id AS task_id,
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
                p.task_id,
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
                t.task_id,
                '{$personnel}',
                NULL,
                t.work_date,
                t.description,
                t.cost
            FROM timesheets t
            WHERE t.cost > 0
            SQL);
    }

    /**
     * Cấu hình đã lưu của "dự án" (ngưỡng ngân sách, biên lợi nhuận…) nay thuộc về công việc.
     */
    private function renameSettings(string $from, string $to): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (DB::table('settings')->where('key', 'like', $from.'%')->pluck('key') as $key) {
            $newKey = $to.substr((string) $key, strlen($from));

            if (DB::table('settings')->where('key', $newKey)->exists()) {
                DB::table('settings')->where('key', $key)->delete();
            } else {
                DB::table('settings')->where('key', $key)->update(['key' => $newKey]);
            }
        }

        // Không còn nhắc "dự án sắp tới hạn".
        DB::table('settings')->where('key', 'job.deadline_warning_days')->delete();
    }

    /**
     * Bỏ quyền của module Dự án / Ngân sách, và chỉ để Giám đốc, Kế toán giữ
     * quyền với hợp đồng, báo giá, nghiệm thu, hóa đơn, công nợ, thanh toán.
     */
    private function tightenPermissions(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['permissions'])) {
            return;
        }

        DB::table($tables['permissions'])
            ->where(fn ($query) => $query->where('name', 'like', 'projects.%')->orWhere('name', 'like', 'budgets.%'))
            ->delete();

        $restricted = DB::table($tables['permissions'])
            ->where(function ($query): void {
                foreach (['quotations', 'contracts', 'acceptances', 'invoices', 'receivables', 'payments'] as $module) {
                    $query->orWhere('name', 'like', $module.'.%');
                }
            })
            ->pluck('name', 'id');

        $roles = DB::table($tables['roles'])->pluck('id', 'name');
        $keepers = array_filter([$roles['Super Admin'] ?? null, $roles['Director'] ?? null, $roles['Accountant'] ?? null]);

        if ($restricted->isNotEmpty()) {
            DB::table($tables['role_has_permissions'])
                ->whereIn('permission_id', $restricted->keys())
                ->whereNotIn('role_id', $keepers)
                ->delete();
        }

        // Giám đốc và Kế toán là người lập báo giá, hợp đồng, nghiệm thu từ nay.
        $grants = [
            'Director' => ['quotations.', 'contracts.', 'acceptances.'],
            'Accountant' => ['quotations.', 'contracts.', 'acceptances.'],
        ];

        foreach ($grants as $roleName => $prefixes) {
            $roleId = $roles[$roleName] ?? null;

            if ($roleId === null) {
                continue;
            }

            foreach ($restricted as $permissionId => $name) {
                $wanted = collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with((string) $name, $prefix));
                // Duyệt báo giá / hợp đồng vẫn là việc của Giám đốc.
                $approval = str_ends_with((string) $name, '.approve') || str_ends_with((string) $name, '.delete');

                if (! $wanted || ($roleName === 'Accountant' && $approval)) {
                    continue;
                }

                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        // Người xem được hợp đồng cũng cần thấy công việc gắn với hợp đồng:
        // Giám đốc toàn quyền với công việc, Kế toán xem mọi công việc.
        $taskGrants = [
            'Director' => DB::table($tables['permissions'])->where('name', 'like', 'tasks.%')->pluck('id')->all(),
            'Accountant' => DB::table($tables['permissions'])->whereIn('name', ['tasks.view', 'tasks.view_all', 'tasks.export'])->pluck('id')->all(),
        ];

        foreach ($taskGrants as $roleName => $permissionIds) {
            foreach (isset($roles[$roleName]) ? $permissionIds : [] as $permissionId) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roles[$roleName],
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Thông báo cũ trỏ tới báo giá, hợp đồng, nghiệm thu, hóa đơn… của người
     * nay không còn quyền xem các mục đó thì bỏ đi (tiêu đề, nội dung thông báo
     * có mã và số tiền). Thông báo trỏ tới trang Dự án cũng bỏ: trang không còn.
     */
    private function pruneNotifications(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $modules = ['quotations', 'contracts', 'acceptances', 'invoices', 'payments', 'receivables'];
        $pattern = '#/admin/('.implode('|', [...$modules, 'projects', 'budget-items']).')(?:[/?\#]|$)#';
        $allowed = [];
        $doomed = [];

        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->orderBy('id')
            ->select(['id', 'notifiable_id', 'data'])
            ->chunk(500, function ($notifications) use ($pattern, $modules, &$allowed, &$doomed): void {
                foreach ($notifications as $notification) {
                    $data = json_decode((string) $notification->data, true);
                    $urls = array_filter(array_column(is_array($data['actions'] ?? null) ? $data['actions'] : [], 'url'));

                    foreach ($urls as $url) {
                        if (! is_string($url) || preg_match($pattern, $url, $match) !== 1) {
                            continue;
                        }

                        if (! in_array($match[1], $modules, true)) {
                            $doomed[] = $notification->id;

                            break;
                        }

                        $key = $notification->notifiable_id.':'.$match[1];
                        $allowed[$key] ??= (bool) User::query()->find($notification->notifiable_id)?->can($match[1].'.view');

                        if (! $allowed[$key]) {
                            $doomed[] = $notification->id;

                            break;
                        }
                    }
                }
            });

        foreach (array_chunk($doomed, 500) as $ids) {
            DB::table('notifications')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Tính lại giờ công và chi phí của các công việc: từ nay chi phí của công
     * việc gồm cả chứng từ gắn vào các công việc con của nó.
     *
     * @param  list<int>  $jobIds  Công việc vừa tạo ra từ dự án.
     */
    private function refreshTasks(array $jobIds): void
    {
        foreach ($jobIds as $jobId) {
            DB::table('tasks')->where('id', $jobId)->update([
                'actual_hours' => (string) (DB::table('timesheets')->where('task_id', $jobId)->sum('hours') ?: 0),
            ]);
        }

        $taskIds = collect($jobIds);

        foreach (['expenses', 'purchase_requests', 'purchase_orders', 'timesheets'] as $table) {
            $taskIds = $taskIds->merge(DB::table($table)->whereNotNull('task_id')->distinct()->pluck('task_id'));
        }

        // Dùng lại đúng cách tính đang chạy của ứng dụng; không gửi thông báo "vượt ngân sách" cho số liệu cũ.
        TaskFinancials::silently(fn () => app(TaskFinancials::class)->recalculateMany($taskIds->all()));

        if ($jobIds !== []) {
            app(TaskSchedule::class)->backfill();
        }
    }
};
