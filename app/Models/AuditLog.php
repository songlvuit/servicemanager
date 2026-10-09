<?php

namespace App\Models;

use App\Support\Audit\AttributeLabels;
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
        Project::class,
        BudgetItem::class,
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

        return self::subjectOptions()[$this->auditable_type] ?? class_basename($this->auditable_type);
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
