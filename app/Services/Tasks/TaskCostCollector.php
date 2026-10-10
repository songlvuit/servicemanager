<?php

namespace App\Services\Tasks;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\Timesheet;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Gom chi phí của một công việc từ các chứng từ gắn vào nó và vào các công
 * việc con của nó (mục 19, 34). Mọi số đều là số TRƯỚC THUẾ, cùng cơ sở với
 * giá trị hợp đồng.
 *
 *                      Cam kết                          Thực tế
 *   Đề nghị chi        Đã duyệt / Đã thanh toán / Hoàn tất   Đã thanh toán / Hoàn tất
 *   Yêu cầu mua hàng   Đã duyệt (chưa lập đơn đặt hàng)      —
 *   Đơn mua hàng       Đã đặt / Đã nhận / Hoàn tất           Đã nhận / Hoàn tất
 *   Chấm công          giờ × đơn giá (nhóm Nhân sự)          giờ × đơn giá
 *
 * "Cam kết" là số luỹ kế, đã gồm cả phần thực tế.
 */
class TaskCostCollector
{
    public const EMPTY = ['committed' => '0.00', 'actual' => '0.00'];

    /**
     * Chi phí đã cam kết và thực tế của công việc.
     *
     * @return array{committed: string, actual: string}
     */
    public function totals(Task $task): array
    {
        $total = self::EMPTY;

        foreach ($this->byCategory($task) as $cost) {
            $total['committed'] = Decimal::add($total['committed'], $cost['committed']);
            $total['actual'] = Decimal::add($total['actual'], $cost['actual']);
        }

        return $total;
    }

    /**
     * @return array<string, array{committed: string, actual: string}> khoá là BudgetCategory->value
     */
    public function byCategory(Task $task): array
    {
        // Chi phí của công việc gồm cả chứng từ gắn vào các công việc con của nó.
        $ids = [$task->getKey(), ...$task->descendantIds()];
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
            ->whereIn('task_id', $ids)
            ->toBase()
            ->select('category', 'status', DB::raw('SUM(amount) as total'))
            ->groupBy('category', 'status')
            ->get();

        foreach ($expenses as $row) {
            $status = ExpenseStatus::from($row->status);

            $add($row->category, $row->total, $status->isCommitted(), $status->isActual());
        }

        $requests = PurchaseRequest::query()
            ->whereIn('task_id', $ids)
            ->where('status', PurchaseRequestStatus::Approved->value)
            ->toBase()
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get();

        foreach ($requests as $row) {
            $add($row->category, $row->total, true, false);
        }

        $orders = PurchaseOrder::query()
            ->whereIn('task_id', $ids)
            ->toBase()
            ->select('category', 'status', DB::raw('SUM(subtotal) as total'))
            ->groupBy('category', 'status')
            ->get();

        foreach ($orders as $row) {
            $status = PurchaseOrderStatus::from($row->status);

            $add($row->category, $row->total, $status->isCommitted(), $status->isActual());
        }

        $labor = Timesheet::query()->whereIn('task_id', $ids)->sum('cost');

        if (Decimal::isPositive($this->number($labor))) {
            $add(BudgetCategory::Personnel->value, $labor, true, true);
        }

        return $costs;
    }

    /**
     * SUM() trả về chuỗi (PostgreSQL) hoặc số (SQLite): đưa về chuỗi 2 số lẻ.
     */
    private function number(mixed $value): string
    {
        return Decimal::round(is_numeric($value) ? (string) $value : '0');
    }
}
