<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Services\Projects\ProjectFinancials;
use App\Services\Scheduling\TaskSchedule;
use App\Support\Decimal;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Công việc: thuộc một dự án, hoặc là việc độc lập (project_id rỗng). Tiến độ
 * dự án = trung bình tiến độ các công việc chưa huỷ của nó, được tính lại mỗi
 * khi công việc thay đổi.
 *
 * Công việc cũng là nơi quản lý tiền của một "job": `budget` do người quản lý
 * nhập, còn `committed_cost` / `actual_cost` chỉ do TaskFinancials ghi, tính
 * từ đề nghị chi, mua hàng và giờ công gắn vào công việc.
 *
 * Công việc là NGUỒN của Lịch làm việc: công việc có ngày bắt đầu tự có một
 * lịch (schedule()) mang loại lịch, giờ, ê-kíp, địa điểm… nhập ở công việc —
 * xem TaskSchedule.
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'description',
        'assignee_id',
        'priority',
        'status',
        'start_date',
        'due_date',
        'estimated_hours',
        'progress',
        'budget',
        // Thông tin lịch của công việc (xem TaskSchedule).
        'schedule_type_id',
        'customer_id',
        'is_all_day',
        'start_time',
        'end_time',
        'crew_ids',
        'location',
        'equipment',
        'external_crew',
        'is_tentative',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => TaskPriority::Medium->value,
        'status' => TaskStatus::Todo->value,
        'estimated_hours' => 0,
        'actual_hours' => 0,
        'progress' => 0,
        'sort_order' => 0,
        'budget' => 0,
        'committed_cost' => 0,
        'actual_cost' => 0,
        'from_schedule' => false,
        'is_all_day' => true,
        'is_tentative' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'progress' => 'integer',
            'sort_order' => 'integer',
            'budget' => 'decimal:2',
            'committed_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'from_schedule' => 'boolean',
            'is_all_day' => 'boolean',
            'is_tentative' => 'boolean',
            'crew_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            // Công việc mới nằm cuối cột của nó trên bảng Kanban.
            $task->sort_order = (int) static::query()
                ->where('project_id', $task->project_id)
                ->where('status', $task->status)
                ->max('sort_order') + 1;
        });

        static::saving(function (Task $task): void {
            if ($task->status === TaskStatus::Done) {
                $task->progress = 100;
            }

            $task->progress = max(0, min(100, (int) $task->progress));
        });

        $recalculate = function (Task $task): void {
            if ($task->project !== null) {
                app(ProjectFinancials::class)->recalculateProgress($task->project);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);

        // Lịch làm việc lấy từ công việc: mỗi lần công việc đổi, lịch của nó đổi theo.
        static::saved(fn (Task $task) => app(TaskSchedule::class)->sync($task));
        static::deleting(fn (Task $task) => app(TaskSchedule::class)->forget($task));
    }

    /**
     * Ê-kíp của công việc: người phụ trách đứng đầu, rồi tới những người được chọn thêm.
     *
     * @return list<int>
     */
    public function crewIds(): array
    {
        $ids = array_map('intval', (array) ($this->crew_ids ?? []));

        if ($this->assignee_id !== null) {
            array_unshift($ids, (int) $this->assignee_id);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Ngân sách còn lại so với chi phí thực tế (âm = đã vượt).
     */
    public function remainingBudget(): string
    {
        return Decimal::sub($this->budget, $this->actual_cost);
    }

    public function hasBudget(): bool
    {
        return Decimal::isPositive($this->budget);
    }

    /**
     * Đã chi quá ngân sách (chỉ xét khi có đặt ngân sách).
     */
    public function isOverBudget(): bool
    {
        return $this->hasBudget() && Decimal::compare($this->actual_cost, $this->budget) > 0;
    }

    /**
     * Đã có chứng từ tiền hoặc giờ công gắn vào: không tự xoá theo lịch.
     */
    public function hasCostDocuments(): bool
    {
        return $this->expenses()->withTrashed()->exists()
            || $this->purchaseRequests()->exists()
            || $this->purchaseOrders()->exists()
            || $this->timesheets()->exists();
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->due_date->lt(today()) && $this->status->isOpen();
    }

    /**
     * Công việc người dùng được xem: tất cả nếu có quyền "Xem tất cả", nếu
     * không chỉ những việc được giao cho mình hoặc mình có trong ê-kíp của lịch.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('tasks.view_all')) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('assignee_id', $user->getKey())
                ->orWhereHas('scheduleEntries', fn (Builder $query) => $query->forUser($user));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('tasks.view_all')
            || $this->assignee_id === $user->getKey()
            || $this->scheduleEntries()->forUser($user)->exists();
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_date', '<', today());
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Mọi lịch gắn với công việc (lịch của chính nó và các lịch gắn thêm).
     *
     * @return HasMany<ScheduleEntry, $this>
     */
    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    /**
     * Lịch của chính công việc này trên Lịch làm việc — do TaskSchedule tạo và
     * cập nhật theo công việc. Không có khi công việc chưa có ngày bắt đầu.
     *
     * @return HasOne<ScheduleEntry, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(ScheduleEntry::class)->where('from_task', true);
    }

    /**
     * @return BelongsTo<ScheduleType, $this>
     */
    public function scheduleType(): BelongsTo
    {
        return $this->belongsTo(ScheduleType::class);
    }

    /**
     * Khách hàng của việc độc lập (việc thuộc dự án thì theo khách hàng của dự án).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
}
