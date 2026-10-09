<?php

namespace App\Models;

use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một bước trong một lần duyệt: vai trò phải duyệt, người đã duyệt, kết quả.
 */
class ApprovalStep extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'approver_role',
        'sequence',
        'status',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStepStatus::class,
            'sequence' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
