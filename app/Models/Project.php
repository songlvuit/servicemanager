<?php

namespace App\Models;

use App\Enums\CostStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\WorkflowException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use App\Services\Projects\ProjectFinancials;
use App\Support\Decimal;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dự án. Các cột tài chính (budget, actual_cost, profit...) và tiến độ do
 * App\Services\Projects\ProjectFinancials tính và ghi — không nhập tay.
 * Trạng thái chỉ do App\Services\Projects\ProjectWorkflow ghi.
 */
class Project extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Dự án';

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'PRJ';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contract_id',
        'name',
        'description',
        'project_manager_id',
        'start_date',
        'end_date',
        'contract_value',
        'drive_url',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ProjectStatus::Planning->value,
        'contract_value' => 0,
        'budget' => 0,
        'committed_cost' => 0,
        'actual_cost' => 0,
        'labor_cost' => 0,
        'supplier_cost' => 0,
        'equipment_cost' => 0,
        'other_cost' => 0,
        'expected_profit' => 0,
        'actual_profit' => 0,
        'profit_margin' => 0,
        'invoiced_amount' => 0,
        'collected_amount' => 0,
        'progress' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'contract_value' => 'decimal:2',
            'budget' => 'decimal:2',
            'committed_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'supplier_cost' => 'decimal:2',
            'equipment_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'expected_profit' => 'decimal:2',
            'actual_profit' => 'decimal:2',
            'profit_margin' => 'decimal:2',
            'invoiced_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'progress' => 'integer',
            'status' => ProjectStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $contract = $project->contract;

            // Rule 1: không thể tạo dự án nếu hợp đồng chưa có hiệu lực.
            if ($contract === null || ! $contract->isActive()) {
                throw new WorkflowException('Chỉ tạo được dự án từ hợp đồng đang hiệu lực.');
            }

            $project->customer_id = $contract->customer_id;
            $project->created_by ??= auth()->id();
            $project->expected_profit = $project->contract_value;
            $project->actual_profit = $project->contract_value;
            $project->profit_margin = Decimal::isPositive($project->contract_value) ? 100 : 0;
        });

        // Đổi giá trị hợp đồng của dự án → tính lại lợi nhuận.
        static::updated(function (Project $project): void {
            if ($project->wasChanged('contract_value')) {
                app(ProjectFinancials::class)->recalculate($project);
            }
        });
    }

    /**
     * Dự án đã hoàn thành hoặc đã huỷ: không ghi nhận thêm ngân sách, chi phí.
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true);
    }

    /**
     * Chỉ xoá được dự án còn ở bước lập kế hoạch và chưa phát sinh chi phí.
     */
    public function isDeletable(): bool
    {
        return $this->status === ProjectStatus::Planning
            && Decimal::isZero($this->committed_cost)
            && Decimal::isZero($this->actual_cost);
    }

    public function costStatus(): CostStatus
    {
        return CostStatus::for($this->budget, $this->actual_cost);
    }

    /**
     * Ngân sách còn có thể cam kết: ngân sách − chi phí đã cam kết.
     */
    public function remainingBudget(): string
    {
        return Decimal::sub($this->budget, $this->committed_cost);
    }

    /**
     * Đã xuất hóa đơn nhưng chưa thu.
     */
    public function outstandingAmount(): string
    {
        return Decimal::sub($this->invoiced_amount, $this->collected_amount);
    }

    public function daysUntilEnd(): ?int
    {
        return $this->end_date === null ? null : (int) today()->diffInDays($this->end_date, false);
    }

    public function isOverdue(): bool
    {
        $days = $this->daysUntilEnd();

        return ! $this->isClosed() && $days !== null && $days < 0;
    }

    /**
     * Dự án người dùng được xem: tất cả nếu có quyền "Xem tất cả", nếu không
     * chỉ những dự án mình quản lý, là thành viên hoặc có công việc được giao.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('projects.view_all')) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('project_manager_id', $user->getKey())
                ->orWhereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->getKey()))
                ->orWhereHas('tasks', fn (Builder $query) => $query->where('assignee_id', $user->getKey()));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->withTrashed()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeOverBudget(Builder $query): void
    {
        $query->whereColumn('actual_cost', '>', 'budget');
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('status', ProjectStatus::Active->value);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<BudgetItem, $this>
     */
    public function budgetItems(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<PurchaseRequest, $this>
     */
    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return HasMany<Timesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    /**
     * @return HasMany<Acceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(Acceptance::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Các lịch làm việc gắn với dự án.
     *
     * @return HasMany<ScheduleEntry, $this>
     */
    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}
