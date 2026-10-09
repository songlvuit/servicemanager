<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\AffectsProjectCosts;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đơn mua hàng gửi nhà cung cấp (mục 25). `subtotal` (trước thuế) tính vào
 * chi phí dự án: cam kết khi đã đặt hàng, thực tế khi đã nhận hàng. Trạng
 * thái và các cột tổng chỉ do App\Services\Purchasing\PurchaseOrderWorkflow ghi.
 */
class PurchaseOrder extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Đơn mua hàng';

    use AffectsProjectCosts;
    use HasDocumentCode;

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'PO';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_request_id',
        'supplier_id',
        'project_id',
        'category',
        'order_date',
        'delivery_date',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PurchaseOrderStatus::Draft->value,
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BudgetCategory::class,
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order): void {
            $order->created_by ??= auth()->id();
        });
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
