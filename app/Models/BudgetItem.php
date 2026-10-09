<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Models\Concerns\Auditable;
use App\Services\Projects\ProjectFinancials;
use Database\Factories\BudgetItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng ngân sách của dự án (mỗi nhóm chi phí một dòng). Người dùng chỉ
 * nhập `planned_amount`; committed_amount, actual_amount và variance do
 * ProjectFinancials ghi.
 */
class BudgetItem extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Ngân sách';

    // Số liệu tính lại, không phải thao tác của người dùng: không ghi nhật ký.
    public const AUDIT_IGNORED = ['committed_amount', 'actual_amount', 'variance'];

    /** @use HasFactory<BudgetItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'category',
        'description',
        'planned_amount',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'planned_amount' => 0,
        'committed_amount' => 0,
        'actual_amount' => 0,
        'variance' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BudgetCategory::class,
            'planned_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Ngân sách thay đổi → tính lại số liệu của dự án.
        $recalculate = function (BudgetItem $item): void {
            if ($item->project !== null) {
                app(ProjectFinancials::class)->recalculate($item->project);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);
    }

    public function costStatus(): CostStatus
    {
        return CostStatus::for($this->planned_amount, $this->actual_amount);
    }

    public function auditLabel(): string
    {
        return 'Ngân sách '.$this->category?->getLabel().' — '.($this->project?->code ?? '#'.$this->project_id);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
