<?php

namespace App\Support;

/**
 * Định dạng tiền tệ dùng chung cho toàn hệ thống.
 *
 * Tiền được lưu trong database bằng DECIMAL(15,2) và truyền trong PHP dưới
 * dạng chuỗi số; không dùng float để tính toán.
 */
class Money
{
    public const CURRENCY = 'VND';

    public const SYMBOL = '₫';

    /**
     * 45000000 → "45.000.000 ₫". VND không có đơn vị lẻ nên làm tròn tới đồng.
     */
    public static function format(int|float|string|null $amount, bool $withSymbol = true): string
    {
        $formatted = number_format((float) ($amount ?? 0), 0, ',', '.');

        return $withSymbol ? $formatted.' '.self::SYMBOL : $formatted;
    }

    /**
     * Dạng rút gọn cho dashboard: 1.500.000.000 → "1,5 tỷ", 45.000.000 → "45 tr".
     */
    public static function short(int|float|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);
        $absolute = abs($value);

        if ($absolute >= 1_000_000_000) {
            return self::trim($value / 1_000_000_000).' tỷ';
        }

        if ($absolute >= 1_000_000) {
            return self::trim($value / 1_000_000).' tr';
        }

        return self::format($value);
    }

    /**
     * Tỷ lệ phần trăm kiểu Việt Nam: "14.35" → "14,35%", "20.00" → "20%".
     */
    public static function percent(int|float|string|null $value): string
    {
        return str_replace('.', ',', Decimal::trimZeros($value ?? 0) ?? '0').'%';
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
