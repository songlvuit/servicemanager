<?php

namespace App\Models;

use App\Enums\ApprovalDocumentType;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Một lần chạy quy trình duyệt cho một chứng từ (mục 61). Chỉ
 * App\Services\Approvals\ApprovalService tạo và cập nhật.
 */
class ApprovalRequest extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ApprovalStatus::Pending->value,
        'amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => ApprovalDocumentType::class,
            'status' => ApprovalStatus::class,
            'amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * Bước đang chờ duyệt (bước chờ có thứ tự nhỏ nhất).
     */
    public function pendingStep(): ?ApprovalStep
    {
        return $this->steps()->where('status', ApprovalStepStatus::Pending->value)->first();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sequence')->orderBy('id');
    }
}
