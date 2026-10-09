<?php

namespace App\Filament\Support;

/**
 * Các kiểm tra quyền dùng lặp lại ở nhiều màn hình.
 */
class Access
{
    public static function can(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    /**
     * Giá vốn, lợi nhuận và biên lợi nhuận chỉ hiện với người có quyền
     * "Chi phí nội bộ & giá vốn" (mặc định Sales không có — mục 6).
     */
    public static function canSeeCosts(): bool
    {
        return self::can('internal_costs.view');
    }
}
