<?php

namespace App\Models;

use App\Enums\ScheduleStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\ScheduleEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Một lịch làm việc: việc gì, lúc nào, ở đâu, ai tham gia, cho khách hàng nào.
 * Mỗi lịch (trừ lịch nghỉ) gắn với một công việc. Lịch có `from_task` là lịch
 * của chính công việc đó: nó được tạo và cập nhật theo công việc (TaskSchedule),
 * không sửa trực tiếp trên trang Lịch.
 * Lịch nghỉ phép là một lịch có loại "nghỉ" (schedule_types.is_leave) và ê-kíp
 * là những người nghỉ.
 *
 * Thời gian luôn là một khoảng [starts_at, ends_at). Lịch "cả ngày" được lưu
 * từ 00:00 ngày đầu tới 23:59:59 ngày cuối.
 */
class ScheduleEntry extends Model
{
    use Auditable;

    /** @use HasFactory<ScheduleEntryFactory> */
    use HasFactory;

    public const AUDIT_NAME = 'Lịch';

    /** Lịch cả ngày kéo dài hơn số ngày này là "việc dài ngày" — xem isBackground(). */
    public const LONG_DAYS = 3;

    public const AUDIT_IGNORED = ['timesheets_logged_at', 'from_task'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'schedule_type_id',
        'customer_id',
        'starts_at',
        'ends_at',
        'is_all_day',
        'location',
        'equipment',
        'external_crew',
        'notes',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_all_day' => false,
        'status' => ScheduleStatus::Confirmed->value,
        'from_task' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
            'status' => ScheduleStatus::class,
            'timesheets_logged_at' => 'datetime',
            'from_task' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ScheduleEntry $entry): void {
            $entry->created_by ??= auth()->id();

            // Lịch cả ngày phủ trọn các ngày của nó.
            if ($entry->is_all_day && $entry->starts_at !== null) {
                $entry->starts_at = $entry->starts_at->copy()->startOfDay();
                $entry->ends_at = ($entry->ends_at ?? $entry->starts_at)->copy()->endOfDay()->startOfSecond();
            }
        });
    }

    public function auditLabel(): string
    {
        return self::AUDIT_NAME.' "'.$this->title.'" ('.$this->starts_at?->format('d/m/Y').')';
    }

    public function isLeave(): bool
    {
        return (bool) $this->type?->is_leave;
    }

    /**
     * "Việc dài ngày": lịch công việc cả ngày kéo dài hơn LONG_DAYS ngày (ví
     * dụ hậu kỳ hai tuần). Người làm việc dài ngày vẫn nhận được việc khác,
     * nên lịch này không giữ chỗ — không tính là trùng lịch — và hiện gọn
     * thành một dải trên lịch thay vì một thẻ mỗi ngày.
     */
    public function isBackground(): bool
    {
        return ! $this->isLeave() && self::isLongSpan($this->starts_at, $this->ends_at, (bool) $this->is_all_day);
    }

    public static function isLongSpan(CarbonInterface $start, CarbonInterface $end, bool $allDay): bool
    {
        return $allDay && $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) >= self::LONG_DAYS;
    }

    public function isMultiDay(): bool
    {
        return ! $this->starts_at->isSameDay($this->ends_at);
    }

    /**
     * Khoảng thời gian dạng chữ: "08:00 – 11:30", "Cả ngày",
     * "07/10 14:00 – 08/10 18:00", "07/10 – 09/10 (cả ngày)".
     */
    public function timeLabel(): string
    {
        if ($this->is_all_day) {
            return $this->isMultiDay()
                ? $this->starts_at->format('d/m').' – '.$this->ends_at->format('d/m').' (cả ngày)'
                : 'Cả ngày';
        }

        return $this->isMultiDay()
            ? $this->starts_at->format('d/m H:i').' – '.$this->ends_at->format('d/m H:i')
            : $this->starts_at->format('H:i').' – '.$this->ends_at->format('H:i');
    }

    /**
     * Khoảng thời gian của lịch trong riêng ngày $day (dùng cho lịch nhiều ngày).
     */
    public function timeLabelOn(CarbonInterface $day): string
    {
        if ($this->is_all_day) {
            return 'Cả ngày';
        }

        if (! $this->isMultiDay()) {
            return $this->timeLabel();
        }

        $startsToday = $this->starts_at->isSameDay($day);
        $endsToday = $this->ends_at->isSameDay($day);

        return match (true) {
            $startsToday => 'Từ '.$this->starts_at->format('H:i'),
            $endsToday => 'Đến '.$this->ends_at->format('H:i'),
            default => 'Cả ngày',
        };
    }

    /**
     * Tên ê-kíp, gồm cả cộng tác viên ngoài: "Giang, Kỷ + CTV Tĩnh".
     */
    public function crewLabel(): string
    {
        $names = $this->crew->pluck('name')->all();

        if (filled($this->external_crew)) {
            $names[] = $this->external_crew;
        }

        return implode(', ', $names);
    }

    /**
     * Lịch có phần nằm trong khoảng [$from, $to].
     *
     * @param  Builder<ScheduleEntry>  $query
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starts_at', '<=', $to)->where('ends_at', '>=', $from);
    }

    /**
     * Lịch có $user trong ê-kíp.
     *
     * @param  Builder<ScheduleEntry>  $query
     */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $query->whereHas('crew', fn (Builder $query) => $query->whereKey($userId));
    }

    /**
     * Lịch còn giữ chỗ (không tính lịch đã huỷ).
     *
     * @param  Builder<ScheduleEntry>  $query
     */
    public function scopeOccupying(Builder $query): void
    {
        $query->where('status', '!=', ScheduleStatus::Cancelled->value);
    }

    /**
     * @return BelongsTo<ScheduleType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ScheduleType::class, 'schedule_type_id');
    }

    /**
     * Công việc của lịch: nơi theo dõi ngân sách và chi phí (lịch nghỉ không có).
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function crew(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedule_entry_user')->withTimestamps()->orderBy('name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Giờ công đã ghi từ lịch này.
     *
     * @return BelongsToMany<Timesheet, $this>
     */
    public function timesheets(): BelongsToMany
    {
        return $this->belongsToMany(Timesheet::class, 'schedule_entry_timesheet')->withTimestamps();
    }
}
