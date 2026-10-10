<?php

namespace App\Services\Projects;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\Timesheet;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Gom chi phí của dự án theo nhóm ngân sách (mục 19, 34). Mọi số đều là số
 * TRƯỚC THUẾ, cùng cơ sở với doanh thu của dự án.
 *
 *                      Cam kết                          Thực tế
 *   Đề nghị chi        Đã duyệt / Đã thanh toán / Hoàn tất   Đã thanh toán / Hoàn tất
 *   Yêu cầu mua hàng   Đã duyệt (chưa lập đơn đặt hàng)      —
 *   Đơn mua hàng       Đã đặt / Đã nhận / Hoàn tất           Đã nhận / Hoàn tất
 *   Chấm công          giờ × đơn giá (nhóm Nhân sự)          giờ × đơn giá
 *
 * "Cam kết" là số luỹ kế, đã gồm cả phần thực tế.
 */
class ProjectCostCollector
{
    public const EMPTY = ['committed' => '0.00', 'actual' => '0.00'];

    /**
     * @return array<string, array{committed: string, actual: string}> khoá là BudgetCategory->value
     */
    public function byCategory(Project $project): array
    {
        return $this->collect('project_id', $project->getKey());
    }

    /**
     * Chi phí đã cam kết và thực tế của MỘT công việc (các chứng từ gắn task_id),
     * cùng quy tắc với chi phí dự án.
     *
     * @return array{committed: string, actual: string}
     */
    public function forTask(Task $task): array
    {
        $total = self::EMPTY;

        foreach ($this->collect('task_id', $task->getKey()) as $cost) {
            $total['committed'] = Decimal::add($total['committed'], $cost['committed']);
            $total['actual'] = Decimal::add($total['actual'], $cost['actual']);
        }

        return $total;
    }

    /**
     * @param  'project_id'|'task_id'  $column
     * @return array<string, array{committed: string, actual: string}> khoá là BudgetCategory->value
     */
    private function collect(string $column, int|string $id): array
    {
        $costs = [];

        $add = function (string $category, mixed $amount, bool $committed, bool $actual) use (&$costs): void {
            $costs[$category] ??= self::EMPTY;

            if ($committed) {
                $costs[$category]['committed'] = Decimal::add($costs[$category]['committed'], $this->number($amount));
            }

            if ($actual) {
                $costs[$category]['actual'] = Decimal::add($costs[$category]['actual'], $this->number($amount));
            }
        };

        $expenses = Expense::query()
            ->where($column, $id)
            ->toBase()
            ->select('category', 'status', DB::raw('SUM(amount) as total'))
            ->groupBy('category', 'status')
            ->get();

        foreach ($expenses as $row) {
            $status = ExpenseStatus::from($row->status);

            $add($row->category, $row->total, $status->isCommitted(), $status->isActual());
        }

        $requests = PurchaseRequest::query()
            ->where($column, $id)
            ->where('status', PurchaseRequestStatus::Approved->value)
            ->toBase()
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get();

        foreach ($requests as $row) {
            $add($row->category, $row->total, true, false);
        }

        $orders = PurchaseOrder::query()
            ->where($column, $id)
            ->toBase()
            ->select('category', 'status', DB::raw('SUM(subtotal) as total'))
            ->groupBy('category', 'status')
            ->get();

        foreach ($orders as $row) {
            $status = PurchaseOrderStatus::from($row->status);

            $add($row->category, $row->total, $status->isCommitted(), $status->isActual());
        }

        $labor = Timesheet::query()->where($column, $id)->sum('cost');

        if (Decimal::isPositive($this->number($labor))) {
            $add(BudgetCategory::Personnel->value, $labor, true, true);
        }

        return $costs;
    }

    /**
     * Chi phí nhân sự dự kiến = Σ giờ dự kiến của công việc × đơn giá của người phụ trách (mục 28).
     */
    public function estimatedLaborCost(Project $project): string
    {
        $total = '0';

        $rows = $project->tasks()
            ->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->toBase()
            ->select('tasks.estimated_hours', 'users.hourly_rate')
            ->get();

        foreach ($rows as $row) {
            $total = Decimal::add($total, Decimal::mul($this->number($row->estimated_hours), $this->number($row->hourly_rate)));
        }

        return $total;
    }

    /**
     * Hóa đơn đã phát hành (gồm thuế) và tiền đã thu của dự án (mục 17).
     *
     * @return array{invoiced: string, collected: string}
     */
    public function receivables(Project $project): array
    {
        $row = Invoice::query()
            ->where('project_id', $project->getKey())
            ->billed()
            ->toBase()
            ->select(DB::raw('SUM(total) as invoiced'), DB::raw('SUM(paid_amount) as collected'))
            ->first();

        return [
            'invoiced' => $this->number($row?->invoiced),
            'collected' => $this->number($row?->collected),
        ];
    }

    /**
     * SUM() trả về chuỗi (PostgreSQL) hoặc số (SQLite): đưa về chuỗi 2 số lẻ.
     */
    private function number(mixed $value): string
    {
        return Decimal::round(is_numeric($value) ? (string) $value : '0');
    }
}
