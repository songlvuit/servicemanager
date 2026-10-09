<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ghi nhật ký thao tác (mục 39): ai, làm gì, trên bản ghi nào, giá trị cũ /
 * mới, từ địa chỉ nào.
 *
 * Model dùng trait App\Models\Concerns\Auditable được ghi tự động khi tạo,
 * sửa, đổi trạng thái, xoá, khôi phục. Các việc khác (đăng nhập, xuất dữ
 * liệu, đổi cấu hình, đổi phân quyền) gọi record() trực tiếp.
 *
 * Ghi nhật ký không bao giờ được làm hỏng nghiệp vụ chính: lỗi khi ghi chỉ
 * được báo vào log của ứng dụng.
 */
class AuditLogger
{
    /**
     * Không ghi các cột này: không mang ý nghĩa nghiệp vụ hoặc là bí mật.
     */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    private const SECRET = ['password', 'calendar_token'];

    /**
     * Cột số tiền chính của chứng từ (lấy cột đầu tiên có trong model) để đưa vào mô tả.
     */
    private const AMOUNT_COLUMNS = ['total_amount', 'total', 'amount', 'contract_value', 'estimated_value'];

    private static bool $enabled = true;

    /**
     * Người thực hiện do quy trình chỉ định (xem actingAs()).
     */
    private static ?Authenticatable $actor = null;

    /**
     * Chạy $callback mà không ghi nhật ký (dùng khi nạp dữ liệu mẫu).
     */
    public static function withoutLogging(Closure $callback): mixed
    {
        $previous = self::$enabled;
        self::$enabled = false;

        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }

    /**
     * Ghi các thao tác trong $callback dưới tên $actor. Các quy trình nghiệp
     * vụ luôn biết ai thực hiện (tham số $by) kể cả khi chạy ngoài một phiên
     * đăng nhập (lệnh artisan, queue, nạp dữ liệu mẫu).
     */
    public static function actingAs(?Authenticatable $actor, Closure $callback): mixed
    {
        $previous = self::$actor;
        self::$actor = $actor ?? $previous;

        try {
            return $callback();
        } finally {
            self::$actor = $previous;
        }
    }

    /**
     * DB::transaction() mà mọi thao tác bên trong được ghi dưới tên $actor.
     */
    public static function transactionAs(?Authenticatable $actor, Closure $callback): mixed
    {
        return self::actingAs($actor, fn (): mixed => DB::transaction($callback));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?string $label = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
    ): ?AuditLog {
        if (! self::$enabled) {
            return null;
        }

        try {
            $user = self::$actor ?? auth()->user() ?? self::creatorOf($action, $subject);
            $request = app()->runningInConsole() ? null : request();

            // Transaction lồng (savepoint): nếu ghi nhật ký lỗi thì chỉ phần
            // này bị huỷ, giao dịch nghiệp vụ bên ngoài vẫn nguyên vẹn.
            return DB::transaction(fn (): AuditLog => AuditLog::create([
                'user_id' => $user?->getAuthIdentifier(),
                'user_name' => $user?->getAttribute('name'),
                'action' => $action,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id' => $subject?->getKey(),
                'label' => Str::limit((string) ($label ?? ($subject ? self::labelFor($subject) : '')), 250, '…') ?: null,
                'description' => $description,
                'old_values' => $old === [] ? null : $old,
                'new_values' => $new === [] ? null : $new,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 490, '') : null,
            ]));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Khi không có ai đăng nhập (lệnh artisan, nạp dữ liệu mẫu), bản ghi mới
     * được tính cho người tạo / người đề nghị ghi trên chính nó.
     */
    private static function creatorOf(string $action, ?Model $subject): ?User
    {
        if ($action !== 'created' || $subject === null) {
            return null;
        }

        foreach (['created_by', 'employee_id', 'requester_id'] as $column) {
            $creator = $subject->getAttribute($column);

            if (is_numeric($creator)) {
                return User::query()->find((int) $creator);
            }
        }

        return null;
    }

    public static function created(Model $model): void
    {
        self::record(
            'created',
            $model,
            description: self::amountSummary($model),
            new: self::clean($model, $model->getAttributes()),
        );
    }

    public static function updated(Model $model): void
    {
        $changes = self::clean($model, $model->getChanges());

        if ($changes === []) {
            return;
        }

        $old = [];

        foreach (array_keys($changes) as $key) {
            $old[$key] = self::scalar($key, $model->getRawOriginal($key));
        }

        if (array_key_exists('status', $changes)) {
            $description = 'Trạng thái: '.self::statusLabel($model, $old['status']).' → '.self::statusLabel($model, $changes['status']);

            if ($amount = self::amountSummary($model)) {
                $description .= '. '.$amount;
            }

            self::record('status_changed', $model, description: $description, old: $old, new: $changes);

            return;
        }

        $fields = array_map(fn (string $key): string => AttributeLabels::for($key), array_keys($changes));

        self::record('updated', $model, description: 'Cập nhật: '.implode(', ', $fields), old: $old, new: $changes);
    }

    public static function deleted(Model $model): void
    {
        self::record('deleted', $model, description: self::amountSummary($model));
    }

    public static function restored(Model $model): void
    {
        self::record('restored', $model);
    }

    /**
     * "Hóa đơn INV-2026-0001", "Khách hàng Công ty ABC"...
     */
    public static function labelFor(Model $model): string
    {
        if (method_exists($model, 'auditLabel')) {
            return $model->auditLabel();
        }

        return class_basename($model).' #'.$model->getKey();
    }

    /**
     * Bỏ các cột không cần ghi, che bí mật, đưa mọi giá trị về dạng lưu được.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function clean(Model $model, array $attributes): array
    {
        $ignored = [...self::IGNORED, ...(method_exists($model, 'auditIgnored') ? $model->auditIgnored() : [])];
        $clean = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $clean[$key] = self::scalar($key, $value);
        }

        return $clean;
    }

    private static function scalar(string $key, mixed $value): mixed
    {
        if (in_array($key, self::SECRET, true)) {
            return '••••••';
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value), $value === null => $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    private static function statusLabel(Model $model, mixed $value): string
    {
        $cast = $model->getCasts()['status'] ?? null;

        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $status = $cast::tryFrom((string) $value);

            if ($status !== null && method_exists($status, 'getLabel')) {
                return (string) $status->getLabel();
            }
        }

        return (string) $value;
    }

    private static function amountSummary(Model $model): ?string
    {
        foreach (self::AMOUNT_COLUMNS as $column) {
            if (array_key_exists($column, $model->getAttributes())) {
                return 'Số tiền: '.Money::format($model->getAttribute($column));
            }
        }

        return null;
    }
}
