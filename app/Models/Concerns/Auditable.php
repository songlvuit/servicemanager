<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Ghi nhật ký thao tác (mục 39) mỗi khi bản ghi được tạo, sửa, đổi trạng
 * thái, xoá hoặc khôi phục. Model dùng trait khai báo tên hiển thị:
 *
 *   public const AUDIT_NAME = 'Hóa đơn';
 *
 *   public const AUDIT_IGNORED = ['tax'];   // tuỳ chọn: cột tính lại, không ghi
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLogger::created($model));
        static::updated(fn (Model $model) => AuditLogger::updated($model));
        static::deleted(fn (Model $model) => AuditLogger::deleted($model));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => AuditLogger::restored($model));
        }
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('id');
    }

    /**
     * Tên bản ghi trong nhật ký: "Hóa đơn INV-2026-0001".
     */
    public function auditLabel(): string
    {
        $name = defined(static::class.'::AUDIT_NAME') ? static::AUDIT_NAME : class_basename(static::class);

        $identifier = $this->getAttribute('code')
            ?? $this->getAttribute('name')
            ?? $this->getAttribute('title')
            ?? ('#'.$this->getKey());

        return trim($name.' '.$identifier);
    }

    /**
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return defined(static::class.'::AUDIT_IGNORED') ? static::AUDIT_IGNORED : [];
    }
}
