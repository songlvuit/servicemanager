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
     *
     * Ô để trống được lưu là 0, vì các cột tiền trong database không nhận
     * giá trị rỗng. Chỉ truyền $nullable = true cho cột mà "bỏ trống" có ý
     * nghĩa riêng (ví dụ "Đến số tiền" của quy trình duyệt = không giới hạn).
     */
    public static function money(string $name, bool $nullable = false): TextInput
    {
        $input = TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->maxValue(self::MAX_MONEY)
            ->suffix('₫')
            ->formatStateUsing(fn (mixed $state): ?string => self::trim($state));

        return $nullable ? $input : self::zeroWhenBlank($input);
    }

    /**
     * Ô nhập tỷ lệ phần trăm 0–100. Để trống được lưu là 0.
     */
    public static function percent(string $name): TextInput
    {
        return self::zeroWhenBlank(
            TextInput::make($name)
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->formatStateUsing(fn (mixed $state): ?string => self::trim($state)),
        );
    }

    /**
     * Ô số không bắt buộc: người dùng xoá trắng thì lưu 0 thay vì giá trị
     * rỗng (cột số trong database là NOT NULL).
     */
    public static function zeroWhenBlank(TextInput $input): TextInput
    {
        return $input->dehydrateStateUsing(fn (mixed $state): mixed => filled($state) ? $state : 0);
    }

    private static function trim(mixed $state): ?string
    {
        if (! is_int($state) && ! is_float($state) && ! is_string($state)) {
            return null;
        }

        return Decimal::trimZeros($state);
    }
}
