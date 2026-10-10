<?php

namespace App\Models;

use App\Enums\AcceptanceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentCode;
use Database\Factories\AcceptanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Biên bản nghiệm thu của hợp đồng (mục 29). Trạng thái chỉ do
 * App\Services\Billing\AcceptanceWorkflow ghi.
 */
class Acceptance extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Nghiệm thu';

    use HasAttachments;
    use HasDocumentCode;

    /** @use HasFactory<AcceptanceFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'NT';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contract_id',
        'description',
        'acceptance_date',
        'amount',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AcceptanceStatus::Draft->value,
        'amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AcceptanceStatus::class,
            'acceptance_date' => 'date',
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Acceptance $acceptance): void {
            $acceptance->created_by ??= auth()->id();
        });
    }

    public function isEditable(): bool
    {
        return $this->status === AcceptanceStatus::Draft;
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
