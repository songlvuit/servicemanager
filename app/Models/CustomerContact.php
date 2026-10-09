<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Liên hệ';

    /** @use HasFactory<CustomerContactFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'position',
        'department',
        'email',
        'phone',
        'is_primary',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Liên hệ đầu tiên của khách hàng mặc định là liên hệ chính.
        static::creating(function (CustomerContact $contact): void {
            if (! static::query()->where('customer_id', $contact->customer_id)->exists()) {
                $contact->is_primary = true;
            }
        });

        // Mỗi khách hàng chỉ có một liên hệ chính.
        static::saved(function (CustomerContact $contact): void {
            if ($contact->is_primary) {
                static::query()
                    ->where('customer_id', $contact->customer_id)
                    ->whereKeyNot($contact->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
