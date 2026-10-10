<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\AffectsProjectCosts;
use App\Models\Concerns\AffectsTaskCosts;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use App\Support\Decimal;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Đề nghị chi (mục 20). `amount` là số trước thuế — tính vào chi phí dự án;
 * `total_amount` = amount + tax là số tiền chi ra, dùng để xét ngưỡng duyệt.
 * Trạng thái chỉ do App\Services\Expenses\ExpenseWorkflow ghi.
 */
class Expense extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Đề nghị chi';

    use AffectsProjectCosts;
    use AffectsTaskCosts;
    use HasApprovals;
    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'EXP';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'task_id',
        'category',
        'description',
        'amount',
        'tax',
        'expense_date',
        'vendor_id',
        'payment_method',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ExpenseStatus::Draft->value,
        'amount' => 0,
        'tax' => 0,
        'total_amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BudgetCategory::class,
            'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'expense_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            $expense->employee_id ??= auth()->id();
        });

        static::saving(function (Expense $expense): void {
            $expense->total_amount = Decimal::add($expense->amount, $expense->tax);
        });
    }

    /**
     * Chỉ đề nghị chi nháp mới sửa / xoá được.
     */
    public function isEditable(): bool
    {
        return $this->status === ExpenseStatus::Draft;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->employee_id === $user->getKey();
    }

    /**
     * Đề nghị chi người dùng được xem: tất cả nếu có quyền "Xem tất cả", nếu
     * không chỉ của mình và của các dự án mình quản lý.
     *
     * @param  Builder<Expense>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('expenses.view_all')) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('employee_id', $user->getKey())
                ->orWhereHas('project', fn (Builder $query) => $query->where('project_manager_id', $user->getKey()));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('expenses.view_all')
            || $this->isOwnedBy($user)
            || $this->project?->project_manager_id === $user->getKey();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'vendor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
