<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use App\Enums\PurchaseRequestStatus;
use App\Models\Concerns\AffectsTaskCosts;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yêu cầu mua hàng cho công việc (mục 24). Trạng thái chỉ do
 * App\Services\Purchasing\PurchaseRequestWorkflow ghi.
 */
class PurchaseRequest extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Yêu cầu mua hàng';

    use AffectsTaskCosts;
    use HasApprovals;
    use HasDocumentCode;

    /** @use HasFactory<PurchaseRequestFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'PR';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'supplier_id',
        'category',
        'description',
        'amount',
        'needed_date',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PurchaseRequestStatus::Draft->value,
        'amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BudgetCategory::class,
            'status' => PurchaseRequestStatus::class,
            'amount' => 'decimal:2',
            'needed_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseRequest $request): void {
            $request->requester_id ??= auth()->id();
        });
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseRequestStatus::Draft;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->requester_id === $user->getKey();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
