<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Task;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Nơi duy nhất tính tiền của MỘT hợp đồng (mục 17, 33–34). Doanh thu là giá
 * trị hợp đồng trước thuế; chi phí là tổng chi phí của các công việc gắn với
 * hợp đồng (chỉ cộng công việc cấp cao nhất — số của nó đã gồm các việc con). Kết quả được ghi vào các cột của hợp đồng để báo cáo sắp xếp, lọc
 * và cộng được ngay trong database:
 *
 *   budget           = Σ ngân sách của các công việc
 *   committed_cost   = Σ chi phí đã cam kết của các công việc (gồm cả phần đã chi)
 *   actual_cost      = Σ chi phí thực tế của các công việc
 *   actual_profit    = contract_value − actual_cost
 *   profit_margin    = actual_profit / contract_value × 100
 *   invoiced_amount  = Σ hóa đơn đã phát hành (gồm thuế)
 *   collected_amount = Σ tiền đã thu
 *
 * Công việc đã huỷ vẫn tính phần chi phí đã phát sinh của nó. Chi phí không
 * gắn công việc nào, hoặc gắn công việc không có hợp đồng, là chi phí chung
 * của công ty — không vào hợp đồng nào.
 *
 * Gọi recalculate() khi: chi phí / ngân sách của một công việc thuộc hợp đồng
 * đổi, công việc được gắn vào hay gỡ khỏi hợp đồng, giá trị hợp đồng đổi, hóa
 * đơn hoặc thanh toán của hợp đồng đổi.
 */
class ContractFinancials
{
    public function recalculate(Contract $contract): Contract
    {
        $costs = Task::query()
            ->where('contract_id', $contract->getKey())
            // Số của công việc cha đã gồm các công việc con: chỉ cộng cấp cao nhất.
            ->whereNull('parent_id')
            ->toBase()
            ->select(
                DB::raw('COALESCE(SUM(budget), 0) as budget'),
                DB::raw('COALESCE(SUM(committed_cost), 0) as committed'),
                DB::raw('COALESCE(SUM(actual_cost), 0) as actual'),
            )
            ->first();

        $billing = Invoice::query()
            ->where('contract_id', $contract->getKey())
            ->billed()
            ->toBase()
            ->select(DB::raw('COALESCE(SUM(total), 0) as invoiced'), DB::raw('COALESCE(SUM(paid_amount), 0) as collected'))
            ->first();

        // Đọc lại giá trị hợp đồng: bản đang giữ trong bộ nhớ có thể đã cũ.
        $value = Contract::query()->withTrashed()->whereKey($contract->getKey())->value('contract_value') ?? $contract->contract_value;
        $actual = $this->number($costs?->actual);
        $profit = Decimal::sub($value, $actual);

        $figures = [
            'budget' => $this->number($costs?->budget),
            'committed_cost' => $this->number($costs?->committed),
            'actual_cost' => $actual,
            'actual_profit' => $profit,
            // Cột DECIMAL(8,2): chặn trường hợp giá trị hợp đồng gần 0 làm tỷ lệ vượt giới hạn.
            'profit_margin' => Decimal::clamp(Decimal::ratio($profit, $value), '-999999.99', '999999.99'),
            'invoiced_amount' => $this->number($billing?->invoiced),
            'collected_amount' => $this->number($billing?->collected),
        ];

        // Ghi thẳng bằng query: đây là số tính ra, không phải một lần "sửa hợp đồng" (không ghi nhật ký).
        Contract::query()->withTrashed()->whereKey($contract->getKey())->update($figures);

        foreach ($figures as $column => $amount) {
            $contract->setAttribute($column, $amount);
        }

        $contract->syncOriginalAttributes(array_keys($figures));

        return $contract;
    }

    /**
     * Tính lại cho các hợp đồng theo id (bỏ qua id rỗng / không còn tồn tại).
     *
     * @param  iterable<int|string|null>  $contractIds
     */
    public function recalculateMany(iterable $contractIds): void
    {
        $ids = array_values(array_unique(array_filter([...$contractIds])));

        if ($ids === []) {
            return;
        }

        foreach (Contract::query()->withTrashed()->whereKey($ids)->get() as $contract) {
            $this->recalculate($contract);
        }
    }

    /**
     * SUM() trả về chuỗi (PostgreSQL) hoặc số (SQLite): đưa về chuỗi 2 số lẻ.
     */
    private function number(mixed $value): string
    {
        return Decimal::round(is_numeric($value) ? (string) $value : '0');
    }
}
