<?php

namespace App\Models;

use App\Models\Concerns\AffectsProjectCosts;
use App\Support\Decimal;
use Database\Factories\TimesheetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ghi nhận giờ công (mục 28). Chi phí nhân sự = hours × hourly_rate, trong đó
 * hourly_rate là đơn giá của nhân viên tại thời điểm ghi nhận. Chi phí này
 * được tính vào nhóm "Nhân sự" của dự án.
 */
class Timesheet extends Model
{
    use AffectsProjectCosts;

    /** @use HasFactory<TimesheetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'task_id',
        'work_date',
        'hours',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Timesheet $timesheet): void {
            $timesheet->user_id ??= auth()->id();

            // Chụp lại đơn giá khi tạo, hoặc khi đổi sang nhân viên khác.
            if (! $timesheet->exists || $timesheet->isDirty('user_id')) {
                $timesheet->hourly_rate = User::query()->find($timesheet->user_id)?->hourly_rate ?? 0;
            }

            $timesheet->cost = Decimal::mul($timesheet->hours, $timesheet->hourly_rate);
        });

        // Cộng lại số giờ thực tế của công việc liên quan (cả công việc cũ nếu vừa đổi).
        $syncTaskHours = function (Timesheet $timesheet): void {
            $taskIds = array_unique(array_filter([$timesheet->task_id, $timesheet->getOriginal('task_id')]));

            foreach ($taskIds as $taskId) {
                Task::query()->whereKey($taskId)->update([
                    'actual_hours' => (string) (static::query()->where('task_id', $taskId)->sum('hours') ?: 0),
                ]);
            }
        };

        static::saved($syncTaskHours);
        static::deleted($syncTaskHours);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }

    /**
     * @param  Builder<Timesheet>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('timesheets.view_all')) {
            return;
        }

        $query->where('user_id', $user->getKey());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
