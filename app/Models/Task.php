<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Services\Projects\ProjectFinancials;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Công việc của dự án. Tiến độ dự án = trung bình tiến độ các công việc
 * chưa huỷ, được tính lại mỗi khi công việc thay đổi.
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
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->due_date->lt(today()) && $this->status->isOpen();
    }

    /**
     * Công việc người dùng được xem: tất cả nếu có quyền "Xem tất cả", nếu
     * không chỉ những việc được giao cho mình.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('tasks.view_all')) {
            return;
        }

        $query->where('assignee_id', $user->getKey());
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
}
