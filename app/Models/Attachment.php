<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Tài liệu đính kèm của một chứng từ (hợp đồng, dự án, chi phí, hóa đơn...).
 * File nằm ở disk riêng tư; tải về qua route attachments.show có kiểm tra quyền.
 */
class Attachment extends Model
{
    use Auditable;

    public const AUDIT_NAME = 'Tài liệu';

    // Số liệu tính lại, không phải thao tác của người dùng: không ghi nhật ký.
    public const AUDIT_IGNORED = ['disk', 'path', 'size', 'mime_type'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'filename',
        'disk',
        'path',
        'mime_type',
        'size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Attachment $attachment): void {
            $attachment->uploaded_by ??= auth()->id();
        });

        // Xoá bản ghi thì xoá luôn file trên đĩa.
        static::deleted(function (Attachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function auditLabel(): string
    {
        return 'Tài liệu '.$this->filename;
    }

    /**
     * Ảnh và PDF xem trực tiếp được trên trình duyệt.
     */
    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    public function humanSize(): string
    {
        return (string) Number::fileSize($this->size, precision: 1);
    }

    public function url(): string
    {
        return route('attachments.show', $this);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
