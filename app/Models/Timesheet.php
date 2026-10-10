<?php

namespace App\Models;

use App\Models\Concerns\AffectsTaskCosts;
use App\Support\Decimal;
use Database\Factories\TimesheetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng CÔNG của một người trong một ngày (mục 28). Đơn vị là BUỔI (công):
 *
 *   chi phí nhân sự = sessions (số buổi) × session_rate (đơn giá một buổi)
 *
 * `session_rate` là đơn giá của người đó tại thời điểm ghi (đổi đơn giá về sau
 * không làm đổi công đã ghi), hoặc đơn giá nhập riêng cho dòng này. Chi phí
 * được tính vào nhóm "Nhân sự" của công việc; dòng không gắn công việc là chi
 * phí chung.
 *
 * `is_auto`: dòng do hệ thống tự sinh từ phân công của công việc và tự đổi
 * theo công việc (App\Services\Tasks\TaskLabor). Người quản lý sửa tay một dòng tự sinh thì dòng
 * đó thành dòng nhập tay — hệ thống không ghi đè nữa.
 */
class Timesheet extends Model
{
    use AffectsTaskCosts;

    /** @use HasFactory<TimesheetFactory> */
    use HasFactory;

    /** Số buổi tối đa của một người trong một ngày. */
    public const MAX_SESSIONS = 6;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'task_id',
        'work_date',
        'sessions',
        'session_rate',
        'description',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_auto' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'sessions' => 'decimal:2',
            'session_rate' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_auto' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Timesheet $timesheet): void {
            $timesheet->user_id ??= auth()->id();

            // Không nhập đơn giá riêng: chụp lại đơn giá buổi của nhân viên khi tạo,
            // hoặc khi đổi sang nhân viên khác.
            $ratedByHand = $timesheet->session_rate !== null && $timesheet->isDirty('session_rate');

            if ($timesheet->session_rate === null || (! $ratedByHand && (! $timesheet->exists || $timesheet->isDirty('user_id')))) {
                $timesheet->session_rate = User::query()->find($timesheet->user_id)?->session_rate ?? 0;
            }

            $timesheet->cost = Decimal::mul($timesheet->sessions, $timesheet->session_rate);

            // Dòng tự sinh bị sửa tay thì thành dòng nhập tay (TaskLabor không ghi đè nữa).
            if ($timesheet->exists && $timesheet->is_auto && $timesheet->isDirty(['user_id', 'task_id', 'work_date', 'sessions', 'session_rate'])) {
                $timesheet->is_auto = false;
            }
        });

        // Cộng lại số buổi thực tế của công việc liên quan (cả công việc cũ nếu vừa đổi).
        $syncTaskSessions = function (Timesheet $timesheet): void {
            $taskIds = array_unique(array_filter([$timesheet->task_id, $timesheet->getOriginal('task_id')]));

            foreach ($taskIds as $taskId) {
                Task::query()->whereKey($taskId)->update([
                    'actual_sessions' => (string) (static::query()->where('task_id', $taskId)->sum('sessions') ?: 0),
                ]);
            }
        };

        static::saved($syncTaskSessions);
        static::deleted($syncTaskSessions);
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
}
