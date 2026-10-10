<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Exceptions\WorkflowException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use App\Services\Contracts\ContractFinancials;
use App\Support\Decimal;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hóa đơn (mục 30).
 *
 *   tax              = subtotal × tax_rate %
 *   total            = subtotal + tax
 *   remaining_amount = total − paid_amount
 *
 * Ba cột trên được tính lại mỗi lần lưu. `paid_amount` chỉ do
 * App\Services\Billing\PaymentService cộng dồn; trạng thái chỉ do
 * App\Services\Billing\InvoiceWorkflow ghi.
 */
class Invoice extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Hóa đơn';

    // Số liệu tính lại, không phải thao tác của người dùng: không ghi nhật ký.
    public const AUDIT_IGNORED = ['tax', 'paid_amount', 'remaining_amount'];

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'INV';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'contract_id',
        'acceptance_id',
        'description',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_rate',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => InvoiceStatus::Draft->value,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax' => 0,
        'total' => 0,
        'paid_amount' => 0,
        'remaining_amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Hóa đơn / tiền thu đổi → tính lại "đã xuất hóa đơn", "đã thu" của hợp đồng.
        $recalculate = function (Invoice $invoice): void {
            app(ContractFinancials::class)->recalculateMany([$invoice->contract_id, $invoice->getOriginal('contract_id')]);
        };

        static::saved($recalculate);
        static::deleted($recalculate);
        static::restored($recalculate);

        static::creating(function (Invoice $invoice): void {
            $invoice->created_by ??= auth()->id();
        });

        static::saving(function (Invoice $invoice): void {
            $invoice->tax = Decimal::percentOf($invoice->subtotal, $invoice->tax_rate);
            $invoice->total = Decimal::add($invoice->subtotal, $invoice->tax);
            $invoice->remaining_amount = Decimal::sub($invoice->total, $invoice->paid_amount);
        });

        // Rule 4: không thể xoá hóa đơn đã phát hành.
        static::deleting(function (Invoice $invoice): void {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new WorkflowException("Không thể xoá hóa đơn {$invoice->code} vì đã phát hành. Hãy huỷ hóa đơn nếu cần.");
            }
        });
    }

    public function isEditable(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    /**
     * Số ngày quá hạn tính tới hôm nay (0 nếu chưa tới hạn hoặc không còn phải thu).
     */
    public function daysOverdue(): int
    {
        if (! $this->status->isOpen() || $this->due_date === null) {
            return 0;
        }

        return max(0, (int) $this->due_date->diffInDays(today(), false));
    }

    public function isPastDue(): bool
    {
        return $this->daysOverdue() > 0;
    }

    /**
     * Hóa đơn đã phát hành và còn phải thu.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', InvoiceStatus::values(InvoiceStatus::open()));
    }

    /**
     * Hóa đơn tính vào doanh thu (đã phát hành, không tính nháp / đã huỷ).
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeBilled(Builder $query): void
    {
        $query->whereIn('status', InvoiceStatus::values(InvoiceStatus::billed()));
    }

    /**
     * Còn phải thu và đã quá ngày đến hạn.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopePastDue(Builder $query): void
    {
        $query->open()->whereDate('due_date', '<', today());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Acceptance, $this>
     */
    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(Acceptance::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('payment_date')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
