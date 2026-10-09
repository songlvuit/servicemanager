<?php

namespace App\Models;

use App\Enums\ApprovalDocumentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Một bước duyệt áp dụng cho một loại chứng từ trong một khoảng giá trị
 * (mục 21). Quy trình của một chứng từ = mọi bước đang bật có khoảng giá trị
 * chứa số tiền của chứng từ, xếp theo `sequence`.
 */
class ApprovalRule extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Bước duyệt';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'document_type',
        'min_amount',
        'max_amount',
        'required_role',
        'sequence',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'min_amount' => 0,
        'sequence' => 10,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => ApprovalDocumentType::class,
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Các bước đang bật áp dụng cho chứng từ loại $type có số tiền $amount, theo thứ tự duyệt.
     *
     * @param  Builder<ApprovalRule>  $query
     */
    public function scopeMatching(Builder $query, ApprovalDocumentType $type, int|float|string $amount): void
    {
        $query
            ->where('is_active', true)
            ->where('document_type', $type->value)
            ->where('min_amount', '<=', $amount)
            ->where(fn (Builder $query) => $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderBy('sequence')
            ->orderBy('id');
    }
}
