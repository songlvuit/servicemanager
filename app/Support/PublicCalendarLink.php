<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Đường dẫn CHỈ-XEM của lịch làm việc, mở được mà không cần đăng nhập (dán
 * vào nhóm chat, mở trên màn hình văn phòng). Ai có đường dẫn đều xem được,
 * nên đường dẫn chứa một mã bí mật dài; đổi mã là đường dẫn cũ hết hiệu lực,
 * tắt là không ai xem được nữa. Mặc định: tắt.
 *
 * Mã lưu trong bảng `settings` (khoá schedule.public_token).
 */
class PublicCalendarLink
{
    private const KEY = 'schedule.public_token';

    public static function token(): ?string
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');
        $token = is_string($value) ? json_decode($value, true) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function isEnabled(): bool
    {
        return self::token() !== null;
    }

    /**
     * Bật (hoặc đổi mã nếu đang bật) và trả về mã mới.
     */
    public static function rotate(): string
    {
        $token = Str::random(40);

        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($token), 'updated_at' => now(), 'created_at' => now()],
        );

        return $token;
    }

    public static function disable(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }

    public static function matches(?string $candidate): bool
    {
        $token = self::token();

        return $token !== null && is_string($candidate) && hash_equals($token, $candidate);
    }

    public static function url(): ?string
    {
        $token = self::token();

        return $token === null ? null : route('calendar.public', ['token' => $token]);
    }

    public static function feedUrl(): ?string
    {
        $token = self::token();

        return $token === null ? null : route('calendar.public.feed', ['token' => $token]);
    }
}
