<?php

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng hàng của đơn mua hàng: subtotal = quantity × unit_price.
 */
class PurchaseOrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'unit_price' => 0,
        'tax_rate' => 0,
        'subtotal' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderItem $item): void {
            $item->subtotal = Decimal::mul($item->quantity, $item->unit_price);
        });
    }

    /**
     * Thuế của dòng = subtotal × tax_rate %.
     */
    public function taxAmount(): string
    {
        return Decimal::percentOf($this->subtotal, $this->tax_rate);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
