<?php

namespace App\Models\Concerns;

use App\Services\Sequences\DocumentSequence;
use Illuminate\Database\Eloquent\Model;

/**
 * Tự gán cột `code` khi tạo bản ghi. Model dùng trait khai báo:
 *
 *   public const CODE_PREFIX = 'CUS';
 *   public const CODE_YEARLY = true;   // tuỳ chọn, mặc định true
 */
trait HasDocumentCode
{
    protected static function bootHasDocumentCode(): void
    {
        static::creating(function (Model $model): void {
            if (filled($model->getAttribute('code'))) {
                return;
            }

            $yearly = defined(static::class.'::CODE_YEARLY') ? static::CODE_YEARLY : true;

            $model->setAttribute('code', app(DocumentSequence::class)->next(static::CODE_PREFIX, $yearly));
        });
    }
}
