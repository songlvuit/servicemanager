<?php

namespace App\Filament\Support;

use Illuminate\Support\Str;

/**
 * Filament mặc định viết hoa từng chữ của nhãn ("Hợp Đồng", "Tạo Hợp Đồng").
 * Tiếng Việt chỉ viết hoa chữ đầu: tiêu đề danh sách là "Hợp đồng", còn trong
 * câu ("Tạo hợp đồng", "Chỉnh sửa hợp đồng") thì giữ chữ thường.
 */
trait VietnameseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
