<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Exceptions\WorkflowException;
use App\Models\Concerns\Auditable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần khách hàng thanh toán cho hóa đơn (mục 31). Chỉ tạo qua
 * App\Services\Billing\PaymentService để hóa đơn được cập nhật cùng lúc.
 * Đã ghi nhận thì không sửa số tiền và không xoá (Rule 5).
 */
class Payment extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Thanh toán';

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_date',
        'amount',
        'payment_method',
        'reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->created_by ??= auth()->id();
        });

        static::updating(function (Payment $payment): void {
            if ($payment->isDirty(['invoice_id', 'customer_id', 'amount', 'payment_date'])) {
                throw new WorkflowException('Không thể sửa hóa đơn, số tiền hoặc ngày của thanh toán đã ghi nhận.');
            }
        });

        // Rule 5: không thể xoá thanh toán đã ghi nhận.
        static::deleting(function (Payment $payment): void {
            throw new WorkflowException('Không thể xoá thanh toán đã ghi nhận.');
        });
    }

    public function auditLabel(): string
    {
        return 'Thanh toán cho hóa đơn '.($this->invoice?->code ?? '#'.$this->invoice_id);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
