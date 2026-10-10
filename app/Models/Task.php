<?php

namespace App\Models;

use App\Enums\CostStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Services\Contracts\ContractFinancials;
use App\Services\Scheduling\TaskSchedule;
use App\Services\Tasks\TaskFinancials;
use App\Services\Tasks\TaskLabor;
use App\Support\Decimal;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Công việc — đơn vị quản lý duy nhất của phần mềm (không còn "dự án").
 *
 * Tiền: `budget` do người quản lý nhập, còn `committed_cost` / `actual_cost`
 * chỉ do TaskFinancials ghi, tính từ đề nghị chi, mua hàng và công của nhân sự gắn
 * vào công việc VÀ các công việc con của nó (ngân sách của việc lớn bao trùm
 * các việc con).
 *
 * Hợp đồng & báo giá: công việc gắn được với một hợp đồng và / hoặc một báo
 * giá (`contract_id`, `quotation_id`). Đây là thông tin kinh doanh: chỉ người
 * có quyền xem Hợp đồng / Báo giá mới thấy trên giao diện. Công việc con luôn
 * theo hợp đồng, báo giá của công việc cha. Chi phí và lợi nhuận của hợp đồng
 * là tổng từ các công việc cấp cao nhất gắn với nó — xem ContractFinancials.
 *
 * Lịch: công việc là NGUỒN của Lịch làm việc — công việc có ngày bắt đầu tự có
 * một lịch (schedule()) mang loại lịch, giờ, ê-kíp, địa điểm… nhập ở công
 * việc; xem TaskSchedule.
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contract_id',
        'quotation_id',
        'parent_id',
        'name',
        'description',
        'assignee_id',
        'priority',
        'status',
        'start_date',
        'due_date',
        'estimated_sessions',
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
        'estimated_sessions' => 0,
        'actual_sessions' => 0,
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
            'estimated_sessions' => 'decimal:2',
            'actual_sessions' => 'decimal:2',
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
                ->where('status', $task->status)
                ->max('sort_order') + 1;
        });

        static::saving(function (Task $task): void {
            if ($task->status === TaskStatus::Done) {
                $task->progress = 100;
            }

            $task->progress = max(0, min(100, (int) $task->progress));

            $task->alignWithContract();
        });

        // Gắn / gỡ hợp đồng, đổi ngân sách, đổi công việc cha, xoá công việc:
        // tiền của công việc cha và của hợp đồng liên quan được tính lại.
        static::saved(function (Task $task): void {
            if ($task->wasChanged('parent_id') || ($task->wasRecentlyCreated && $task->parent_id !== null)) {
                app(TaskFinancials::class)->recalculateMany([$task->parent_id, $task->getOriginal('parent_id')]);
            }

            if ($task->wasRecentlyCreated || $task->wasChanged(['contract_id', 'budget', 'parent_id'])) {
                app(ContractFinancials::class)->recalculateMany([$task->contract_id, $task->getOriginal('contract_id')]);
            }
        });
        static::deleted(function (Task $task): void {
            app(TaskFinancials::class)->recalculateMany([$task->parent_id]);
            app(ContractFinancials::class)->recalculateMany([$task->contract_id]);
        });

        // Lịch làm việc lấy từ công việc: mỗi lần công việc đổi, lịch của nó đổi theo.
        static::saved(fn (Task $task) => app(TaskSchedule::class)->sync($task));
        static::deleting(fn (Task $task) => app(TaskSchedule::class)->forget($task));

        // Công của nhân sự tự sinh theo phân công: đổi người, ngày, giờ, trạng thái thì công đổi theo.
        static::saved(function (Task $task): void {
            if ($task->wasRecentlyCreated || $task->wasChanged(TaskLabor::RELEVANT)) {
                app(TaskLabor::class)->sync($task);
            }
        });
        static::deleting(fn (Task $task) => app(TaskLabor::class)->forget($task));
    }

    /**
     * Giữ hợp đồng, báo giá và khách hàng của công việc khớp nhau:
     *   - công việc con theo hợp đồng / báo giá của công việc cha;
     *   - chọn hợp đồng mà chưa chọn báo giá thì lấy báo giá của hợp đồng;
     *   - khách hàng là khách hàng của hợp đồng, nếu không thì của báo giá.
     */
    private function alignWithContract(): void
    {
        if ($this->parent_id !== null) {
            $parent = static::query()->whereKey($this->parent_id)->first(['contract_id', 'quotation_id']);

            if ($parent !== null) {
                $this->contract_id = $parent->contract_id;
                $this->quotation_id = $parent->quotation_id;
            }
        }

        if ($this->exists && ! $this->isDirty(['contract_id', 'quotation_id', 'customer_id'])) {
            return;
        }

        if ($this->contract_id !== null) {
            $contract = Contract::query()->withTrashed()->whereKey($this->contract_id)->first(['customer_id', 'quotation_id']);

            if ($contract !== null) {
                if ($this->quotation_id === null && $this->isDirty('contract_id')) {
                    $this->quotation_id = $contract->quotation_id;
                }

                $this->customer_id = $contract->customer_id;
            }

            return;
        }

        if ($this->quotation_id !== null) {
            $this->customer_id = Quotation::query()->withTrashed()->whereKey($this->quotation_id)->value('customer_id') ?? $this->customer_id;
        }
    }

    /**
     * Gắn (hoặc gỡ) hợp đồng / báo giá cho công việc và mọi công việc con của nó.
     *
     * @param  array{contract_id?: mixed, quotation_id?: mixed}  $link  Chỉ những khoá có mặt mới được đổi.
     */
    public function linkToContract(array $link): void
    {
        $this->fill(array_intersect_key($link, ['contract_id' => true, 'quotation_id' => true]));

        // Gỡ hợp đồng mà không nói gì về báo giá thì báo giá giữ nguyên.
        $this->save();

        // Công việc con (mọi cấp) tự lấy lại hợp đồng, báo giá của cha khi lưu.
        foreach (static::query()->whereKey($this->descendantIds())->orderBy('id')->get() as $child) {
            $child->contract_id = $this->contract_id;
            $child->quotation_id = $this->quotation_id;
            $child->save();
        }
    }

    /**
     * Id của mọi công việc con, cháu… của công việc này (không gồm chính nó).
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $found = [];
        $level = [$this->getKey()];

        // Giới hạn số cấp để một vòng lặp cha–con (nếu lỡ có) không làm treo.
        for ($depth = 0; $depth < 10 && $level !== []; $depth++) {
            $level = static::query()
                ->whereIn('parent_id', $level)
                ->whereKeyNot([$this->getKey(), ...$found])
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $found = [...$found, ...$level];
        }

        return $found;
    }

    public function isCancelled(): bool
    {
        return $this->status === TaskStatus::Cancelled;
    }

    /**
     * Tình trạng chi phí so với ngân sách (Rule 6–7). Chưa đặt ngân sách thì không cảnh báo.
     */
    public function costStatus(): CostStatus
    {
        return $this->hasBudget() ? CostStatus::for($this->budget, $this->actual_cost) : CostStatus::NoBudget;
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
     * Đã có chứng từ tiền hoặc công của nhân sự gắn vào: không tự xoá theo lịch.
     */
    public function hasCostDocuments(): bool
    {
        return $this->expenses()->withTrashed()->exists()
            || $this->purchaseRequests()->exists()
            || $this->purchaseOrders()->exists()
            // Công tự sinh theo phân công không tính là "đã có chứng từ".
            || $this->timesheets()->where('is_auto', false)->exists();
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
     * Công việc có đặt ngân sách và chi phí thực tế đã vượt ngân sách (Rule 7).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOverBudget(Builder $query): void
    {
        $query->where('budget', '>', 0)->whereColumn('actual_cost', '>', 'budget');
    }

    /**
     * Công việc có đặt ngân sách và chi phí thực tế đã đạt ngưỡng cảnh báo (Rule 6), kể cả đã vượt.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeNearBudget(Builder $query): void
    {
        $query
            ->where('budget', '>', 0)
            ->whereRaw('actual_cost * 100 >= budget * ?', [(float) config('job.budget_warning_threshold', 80)]);
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_date', '<', today());
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
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
     * Khách hàng của công việc (tự lấy theo hợp đồng / báo giá nếu có gắn).
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
     * Mọi khoản chi phí gắn thẳng vào công việc này (đề nghị chi, mua hàng,
     * công của nhân sự). Kèm cả công việc con: TaskCostItem::forTask().
     *
     * @return HasMany<TaskCostItem, $this>
     */
    public function costItems(): HasMany
    {
        return $this->hasMany(TaskCostItem::class);
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
