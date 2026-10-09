<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một dịch vụ trong danh mục: giá bán và giá vốn mặc định dùng để điền sẵn
 * khi thêm hạng mục vào báo giá.
 */
class Service extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Dịch vụ';

    use HasDocumentCode;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'SV';

    public const CODE_YEARLY = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'description',
        'default_unit',
        'default_price',
        'default_cost',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ServiceStatus::Active->value,
        'default_price' => 0,
        'default_cost' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'default_cost' => 'decimal:2',
            'status' => ServiceStatus::class,
        ];
    }

    /**
     * @param  Builder<Service>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ServiceStatus::Active->value);
    }

    /**
     * @return HasMany<QuotationItem, $this>
     */
    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }
}
