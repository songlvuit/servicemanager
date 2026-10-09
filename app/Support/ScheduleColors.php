<?php

namespace App\Support;

/**
 * Bảng màu của các loại lịch. Lưu KHOÁ màu (không lưu mã hex) để có thể chỉnh
 * sắc độ về sau mà không phải sửa dữ liệu. Các màu lấy từ cùng bảng màu đã
 * kiểm tra của biểu đồ (phân biệt được với người mù màu, cả nền sáng và tối);
 * trên lịch, màu luôn đi kèm tên loại nên không ai phải đoán nghĩa của màu.
 */
class ScheduleColors
{
    public const DEFAULT = 'blue';

    /**
     * Khoá => [nhãn, mã màu].
     */
    private const COLORS = [
        'blue' => ['Xanh dương', '#3987e5'],
        'orange' => ['Cam', '#d95926'],
        'green' => ['Xanh ngọc', '#199e70'],
        'amber' => ['Vàng đất', '#c98500'],
        'pink' => ['Hồng', '#d55181'],
        'forest' => ['Xanh lá', '#008300'],
        'violet' => ['Tím', '#9085e9'],
        'coral' => ['Đỏ san hô', '#e66767'],
        'gray' => ['Xám', '#898781'],
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $color): string => $color[0], self::COLORS);
    }

    public static function hex(?string $key): string
    {
        return self::COLORS[$key][1] ?? self::COLORS[self::DEFAULT][1];
    }

    public static function label(?string $key): string
    {
        return self::COLORS[$key][0] ?? self::COLORS[self::DEFAULT][0];
    }
}
