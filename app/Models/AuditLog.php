<?php

namespace App\Models;

use App\Support\Audit\AttributeLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Một dòng nhật ký thao tác (mục 39). Chỉ được ghi qua
 * App\Support\Audit\AuditLogger; đã ghi thì không sửa, không xoá.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Hành động => [nhãn, màu badge].
     */
    public const ACTIONS = [
        'created' => ['Tạo mới', 'success'],
        'updated' => ['Cập nhật', 'gray'],
        'status_changed' => ['Đổi trạng thái', 'info'],
        'deleted' => ['Xoá', 'danger'],
        'restored' => ['Khôi phục', 'warning'],
        'login' => ['Đăng nhập', 'gray'],
        'exported' => ['Xuất dữ liệu', 'warning'],
        'settings_updated' => ['Đổi cấu hình', 'warning'],
        'permissions_updated' => ['Đổi phân quyền', 'danger'],
    ];

    /**
     * Loại bản ghi đã bỏ khỏi phần mềm nhưng còn trong nhật ký cũ.
     *
     * @var array<string, string>
     */
    public const RETIRED_SUBJECTS = [
        'App\\Models\\Project' => 'Dự án (đã bỏ)',
        'App\\Models\\BudgetItem' => 'Ngân sách dự án (đã bỏ)',
    ];

    /**
     * Các loại bản ghi được ghi nhật ký tự động (model dùng trait Auditable).
     *
     * @var list<class-string<Model>>
     */
    public const SUBJECTS = [
        Customer::class,
        CustomerContact::class,
        Opportunity::class,
        Service::class,
        Quotation::class,
        Contract::class,
        Expense::class,
        Supplier::class,
        PurchaseRequest::class,
        PurchaseOrder::class,
        Acceptance::class,
        Invoice::class,
        Payment::class,
        Attachment::class,
        ScheduleEntry::class,
        ScheduleType::class,
        ApprovalRule::class,
        User::class,
    ];

    /**
     * Loại bản ghi => quyền cần có để thấy hoạt động của loại đó ở những nơi
     * hiện nhật ký của CHÍNH người dùng (dashboard). Người từng lập báo giá,
     * hợp đồng… nhưng nay không còn quyền xem thì không thấy lại chúng ở đó.
     *
     * @var array<class-string|string, string>
     */
    public const SUBJECT_PERMISSIONS = [
        Quotation::class => 'quotations.view',
        Contract::class => 'contracts.view',
        Acceptance::class => 'acceptances.view',
        Invoice::class => 'invoices.view',
        Payment::class => 'payments.view',
        // Dự án cũ mang giá trị hợp đồng.
        'App\\Models\\Project' => 'contracts.view',
        'App\\Models\\BudgetItem' => 'internal_costs.view',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Nhật ký thao tác không được sửa.');
        });

        static::deleting(function (): void {
            throw new LogicException('Nhật ký thao tác không được xoá.');
        });
    }

    /**
     * Bỏ hoạt động trên những loại bản ghi người dùng không (còn) được xem.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeWithoutSubjectsHiddenFrom(Builder $query, User $user): void
    {
        $hidden = [];

        foreach (self::SUBJECT_PERMISSIONS as $class => $permission) {
            if (! $user->can($permission)) {
                $hidden[] = class_exists($class) ? (new $class)->getMorphClass() : $class;
            }
        }

        if ($hidden !== []) {
            $query->where(fn (Builder $query) => $query->whereNull('auditable_type')->orWhereNotIn('auditable_type', $hidden));
        }
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action][0] ?? $this->action;
    }

    public function actionColor(): string
    {
        return self::ACTIONS[$this->action][1] ?? 'gray';
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return array_map(fn (array $action): string => $action[0], self::ACTIONS);
    }

    /**
     * Loại bản ghi (giá trị cột auditable_type) => tên tiếng Việt.
     *
     * @return array<string, string>
     */
    public static function subjectOptions(): array
    {
        $options = [];

        foreach (self::SUBJECTS as $class) {
            $options[(new $class)->getMorphClass()] = defined($class.'::AUDIT_NAME') ? $class::AUDIT_NAME : class_basename($class);
        }

        return $options;
    }

    public function subjectLabel(): ?string
    {
        if ($this->auditable_type === null) {
            return null;
        }

        return self::subjectOptions()[$this->auditable_type]
            ?? self::RETIRED_SUBJECTS[$this->auditable_type]
            ?? class_basename($this->auditable_type);
    }

    /**
     * Các thay đổi dạng bảng: tên trường, giá trị trước, giá trị sau.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $rows[] = [
                'field' => AttributeLabels::for((string) $key),
                'old' => array_key_exists($key, $old) ? AttributeLabels::display($old[$key]) : '',
                'new' => array_key_exists($key, $new) ? AttributeLabels::display($new[$key]) : '',
            ];
        }

        return $rows;
    }

    public function actorName(): string
    {
        return $this->user_name ?? 'Hệ thống';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
