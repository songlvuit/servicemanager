<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một khoản CHI PHÍ CỦA CÔNG VIỆC (trước thuế), đọc từ view `task_cost_items`:
 * đề nghị chi đã duyệt / đã thanh toán, yêu cầu mua hàng đã duyệt, đơn mua
 * hàng đã đặt / đã nhận và công của nhân sự. `is_actual` cho biết khoản đó đã
 * là chi phí thực tế hay mới là cam kết. Tổng của danh sách này khớp với
 * `committed_cost` / `actual_cost` của công việc (TaskCostCollector).
 *
 * Dòng không gắn công việc (task_id rỗng) là chi phí chung. Chỉ đọc.
 */
class TaskCostItem extends Model
{
    public const SOURCES = [
        'expense' => 'Đề nghị chi',
        'purchase_request' => 'Yêu cầu mua hàng',
        'purchase_order' => 'Đơn mua hàng',
        'timesheet' => 'Công nhân sự',
    ];

    protected $table = 'task_cost_items';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BudgetCategory::class,
            'cost_date' => 'date',
            'sessions' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_actual' => 'boolean',
        ];
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? (string) $this->source;
    }

    public function stageLabel(): string
    {
        return $this->is_actual ? 'Thực tế' : 'Đã cam kết';
    }

    /**
     * Ai / đơn vị nào nhận khoản này: nhà cung cấp, hoặc người làm (công nhân sự).
     */
    public function partyName(): ?string
    {
        return $this->source === 'timesheet'
            ? $this->user?->name
            : $this->supplier?->name;
    }

    /**
     * Dòng phụ dưới tên: "2 buổi × 600.000 ₫" cho công, người đề nghị cho chứng từ.
     */
    public function partyDetail(): ?string
    {
        if ($this->source === 'timesheet') {
            return Decimal::trimZeros($this->sessions).' buổi × '.Money::format($this->unit_price);
        }

        return $this->user !== null ? 'Người lập: '.$this->user->name : null;
    }

    /**
     * Trang của chứng từ gốc (công nhân sự không có trang riêng).
     */
    public function sourceUrl(): ?string
    {
        return match ($this->source) {
            'expense' => ExpenseResource::getUrl('view', ['record' => $this->source_id]),
            'purchase_request' => PurchaseRequestResource::getUrl('view', ['record' => $this->source_id]),
            'purchase_order' => PurchaseOrderResource::getUrl('view', ['record' => $this->source_id]),
            default => null,
        };
    }

    /**
     * Khoản chi của một công việc và mọi công việc con của nó — cùng phạm vi
     * với chi phí của công việc.
     *
     * @param  Builder<TaskCostItem>  $query
     */
    public function scopeForTask(Builder $query, Task $task): void
    {
        $query->whereIn('task_id', [$task->getKey(), ...$task->descendantIds()]);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
