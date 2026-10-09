<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\QuotationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use App\Support\Decimal;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Báo giá. Trạng thái và các cột tổng hợp (subtotal, total, margin...) chỉ do
 * App\Services\Quotations\QuotationWorkflow ghi — không nhận từ form.
 */
class Quotation extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Báo giá';

    // Số liệu tính lại, không phải thao tác của người dùng: không ghi nhật ký.
    public const AUDIT_IGNORED = ['subtotal', 'discount', 'tax', 'estimated_cost', 'estimated_profit', 'estimated_margin'];

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'QT';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'opportunity_id',
        'title',
        'description',
        'quotation_date',
        'valid_until',
        'currency',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => QuotationStatus::Draft->value,
        'currency' => 'VND',
        'subtotal' => 0,
        'discount' => 0,
        'tax' => 0,
        'total' => 0,
        'estimated_cost' => 0,
        'estimated_profit' => 0,
        'estimated_margin' => 0,
        'requires_director_approval' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'estimated_profit' => 'decimal:2',
            'estimated_margin' => 'decimal:2',
            'status' => QuotationStatus::class,
            'requires_director_approval' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            $quotation->created_by ??= auth()->id();
        });
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Tổng giá trị trước chiết khấu (Σ số lượng × đơn giá).
     */
    public function grossAmount(): string
    {
        return Decimal::add($this->subtotal, $this->discount);
    }

    /**
     * Tỷ lệ chiết khấu trên giá trị trước chiết khấu (%).
     */
    public function discountPercent(): string
    {
        return Decimal::ratio($this->discount, $this->grossAmount());
    }

    /**
     * Rule 8: biên lợi nhuận dưới ngưỡng cấu hình thì cảnh báo.
     */
    public function hasLowMargin(): bool
    {
        return Decimal::compare($this->estimated_margin, config('quotation.low_margin_threshold')) < 0;
    }

    public function exceedsDirectorDiscountThreshold(): bool
    {
        return Decimal::compare($this->discountPercent(), config('quotation.director_discount_threshold')) > 0;
    }

    /**
     * Đã quá ngày hiệu lực (tính theo ngày, không tính giờ).
     */
    public function isPastValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(today());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Báo giá đã được khách chấp nhận và chưa có hợp đồng (không tính hợp đồng đã huỷ).
     */
    public function isAwaitingContract(): bool
    {
        return $this->status === QuotationStatus::Accepted
            && $this->contracts()->where('status', '!=', ContractStatus::Cancelled->value)->doesntExist();
    }
}
