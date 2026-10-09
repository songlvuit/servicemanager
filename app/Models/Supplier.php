<?php

namespace App\Models;

use App\Enums\SupplierStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Nhà cung cấp';

    use HasDocumentCode;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'SUP';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'tax_code',
        'contact_name',
        'phone',
        'email',
        'address',
        'bank_name',
        'bank_account',
        'status',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => SupplierStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier): void {
            $supplier->created_by ??= auth()->id();
        });
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'vendor_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
