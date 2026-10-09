<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasDocumentCode;
use App\Support\Decimal;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Cơ hội';

    use HasDocumentCode;

    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'OPP';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'title',
        'description',
        'estimated_value',
        'probability',
        'expected_close_date',
        'sales_owner_id',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OpportunityStatus::New->value,
        'estimated_value' => 0,
        'probability' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'status' => OpportunityStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Opportunity $opportunity): void {
            $opportunity->sales_owner_id ??= auth()->id();
        });
    }

    /**
     * Doanh thu kỳ vọng = giá trị ước tính × xác suất / 100 (mục 10).
     *
     * @return Attribute<string, never>
     */
    protected function expectedRevenue(): Attribute
    {
        return Attribute::get(
            fn (): string => Decimal::percentOf($this->estimated_value, $this->probability),
        );
    }

    /**
     * @param  Builder<Opportunity>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (OpportunityStatus $status): string => $status->value,
            OpportunityStatus::openCases(),
        ));
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
    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }
}
