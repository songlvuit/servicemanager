<?php

namespace App\Models;

use App\Exceptions\QuotationWorkflowException;
use App\Services\Quotations\QuotationCalculator;
use Database\Factories\QuotationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một hạng mục của báo giá.
 *
 * `estimated_cost` là giá vốn ước tính cho MỘT đơn vị; giá vốn của cả hạng mục
 * là quantity × estimated_cost.
 */
class QuotationItem extends Model
{
    /** @use HasFactory<QuotationItemFactory> */
    use HasFactory;

    /**
     * `subtotal` luôn được tính lại khi lưu, không nhận từ form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'discount',
        'tax_rate',
        'estimated_cost',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'unit_price' => 0,
        'discount' => 0,
        'tax_rate' => 0,
        'subtotal' => 0,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (QuotationItem $item): void {
            $item->guardQuotationIsEditable();
            $item->applyDefaultCost();

            $item->discount ??= 0;
            $item->tax_rate ??= 0;
            $item->subtotal = app(QuotationCalculator::class)->lineSubtotal($item);
        });

        static::deleting(function (QuotationItem $item): void {
            $item->guardQuotationIsEditable();
        });
    }

    /**
     * Hạng mục chỉ thay đổi được khi báo giá còn là bản nháp: sau khi gửi
     * duyệt, con số đã duyệt không được phép khác đi.
     */
    private function guardQuotationIsEditable(): void
    {
        $quotation = $this->quotation()->withTrashed()->first();

        if ($quotation !== null && ! $quotation->isEditable()) {
            throw new QuotationWorkflowException(
                "Báo giá {$quotation->code} đang ở trạng thái \"{$quotation->status->getLabel()}\" nên không thể thay đổi hạng mục."
            );
        }
    }

    /**
     * Người không có quyền xem giá vốn sẽ không gửi `estimated_cost` từ form:
     * khi đó lấy giá vốn mặc định của dịch vụ (và lấy lại nếu đổi dịch vụ).
     */
    private function applyDefaultCost(): void
    {
        $costProvided = $this->isDirty('estimated_cost') && $this->estimated_cost !== null;

        if ($costProvided) {
            return;
        }

        if (! $this->exists || $this->isDirty('service_id')) {
            // Truy vấn trực tiếp: quan hệ `service` có thể đang giữ dịch vụ cũ.
            $this->estimated_cost = $this->service_id
                ? (Service::query()->whereKey($this->service_id)->value('default_cost') ?? 0)
                : 0;
        }

        $this->estimated_cost ??= 0;
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
