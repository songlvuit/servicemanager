<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use App\Support\Decimal;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hợp đồng. `contract_value` là giá trị trước thuế (doanh thu); `total_value`
 * = contract_value + tax_amount và được tính lại mỗi lần lưu. Trạng thái chỉ
 * do App\Services\Contracts\ContractWorkflow ghi.
 */
class Contract extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Hợp đồng';

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'HD';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'quotation_id',
        'title',
        'contract_value',
        'tax_amount',
        'sign_date',
        'start_date',
        'end_date',
        'payment_terms',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ContractStatus::Draft->value,
        'contract_value' => 0,
        'tax_amount' => 0,
        'total_value' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contract_value' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_value' => 'decimal:2',
            'sign_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'status' => ContractStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            $contract->created_by ??= auth()->id();
        });

        static::saving(function (Contract $contract): void {
            $contract->total_value = Decimal::add($contract->contract_value, $contract->tax_amount);
        });
    }

    /**
     * Chỉ hợp đồng nháp mới sửa / xoá được.
     */
    public function isEditable(): bool
    {
        return $this->status === ContractStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    /**
     * Số ngày còn lại tới ngày hết hạn (âm nếu đã quá hạn); null nếu chưa có ngày kết thúc.
     */
    public function daysUntilEnd(): ?int
    {
        return $this->end_date === null ? null : (int) today()->diffInDays($this->end_date, false);
    }

    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilEnd();

        return $this->isActive()
            && $days !== null
            && $days <= (int) config('project.contract_expiry_warning_days', 30);
    }

    /**
     * Hợp đồng đang hiệu lực sẽ hết hạn trong $days ngày tới (kể cả đã quá hạn).
     *
     * @param  Builder<Contract>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query
            ->where('status', ContractStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', today()->addDays($days));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
