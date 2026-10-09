<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Xem / tải tài liệu đính kèm. File nằm ở disk riêng tư nên chỉ đi qua đây,
 * sau khi kiểm tra người dùng được xem chứng từ chứa file.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($request->user()?->is_active, 403);

        Gate::authorize('view', $attachment);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        // Ảnh và PDF mở ngay trên trình duyệt (xem trước); loại khác thì tải về.
        return $disk->response(
            $attachment->path,
            $attachment->filename,
            ['X-Content-Type-Options' => 'nosniff'],
            $attachment->isPreviewable() ? 'inline' : 'attachment',
        );
    }
}
