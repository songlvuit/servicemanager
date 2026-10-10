<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Khách hàng';

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_PREFIX = 'CUS';

    /**
     * `code` và `created_by` do hệ thống gán, không nhận từ form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'tax_code',
        'company_type',
        'industry',
        'address',
        'province',
        'website',
        'email',
        'phone',
        'status',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => CustomerStatus::Lead->value,
        'company_type' => CustomerType::Company->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'company_type' => CustomerType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            $customer->created_by ??= auth()->id();
        });
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * @return HasOne<CustomerContact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(CustomerContact::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
