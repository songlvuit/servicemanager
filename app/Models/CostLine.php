<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng CHI PHÍ THỰC TẾ (trước thuế), đọc từ view `project_cost_lines`:
 * đề nghị chi đã thanh toán, đơn mua hàng đã nhận hàng và giờ công đã chấm.
 * Dòng không gắn dự án (project_id rỗng) là chi phí chung của công ty.
 * Chỉ đọc — dùng cho báo cáo và biểu đồ.
 */
class CostLine extends Model
{
    public const SOURCES = [
        'expense' => 'Đề nghị chi',
        'purchase_order' => 'Đơn mua hàng',
        'timesheet' => 'Chấm công',
    ];

    protected $table = 'project_cost_lines';

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
            'amount' => 'decimal:2',
        ];
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? (string) $this->source;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * Công việc mà khoản chi được tính vào (nếu chứng từ có gắn công việc).
     *
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
}
