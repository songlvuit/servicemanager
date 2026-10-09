<?php

namespace App\Filament\Support;

use App\Support\Decimal;
use Filament\Forms\Components\TextInput;

/**
 * Các ô nhập dùng chung để mọi form có cùng cách nhập tiền và phần trăm.
 */
class Fields
{
    // Giá trị lớn nhất của cột DECIMAL(15,2).
    public const MAX_MONEY = 9999999999999;

    /**
     * Ô nhập số tiền VND (không âm). Giá trị "1500000.00" từ database được
     * hiển thị gọn thành "1500000".
     */
    public static function money(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->maxValue(self::MAX_MONEY)
            ->suffix('₫')
            ->formatStateUsing(fn (mixed $state): ?string => self::trim($state));
    }

    /**
     * Ô nhập tỷ lệ phần trăm 0–100.
     */
    public static function percent(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->suffix('%')
            ->formatStateUsing(fn (mixed $state): ?string => self::trim($state));
    }

    private static function trim(mixed $state): ?string
    {
        if (! is_int($state) && ! is_float($state) && ! is_string($state)) {
            return null;
        }

        return Decimal::trimZeros($state);
    }
}
