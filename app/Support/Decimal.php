<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/**
 * Phép tính số thập phân chính xác cho tiền tệ (không dùng float).
 *
 * Mọi giá trị vào/ra đều là chuỗi số, khớp với kiểu DECIMAL của database và
 * cast "decimal:2" của Eloquent. Dùng brick/math — thư viện đi kèm Laravel.
 */
final class Decimal
{
    public const MONEY_SCALE = 2;

    /**
     * Chuẩn hoá một giá trị bất kỳ thành BigDecimal; giá trị rỗng hoặc không
     * phải số được coi là 0 (form đang nhập dở có thể gửi chuỗi rỗng).
     */
    public static function of(int|float|string|null $value): BigDecimal
    {
        if ($value === null) {
            return BigDecimal::zero();
        }

        // brick/math không còn nhận float trực tiếp: chuyển qua chuỗi.
        if (is_float($value)) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return BigDecimal::zero();
            }
        }

        try {
            return BigDecimal::of($value);
        } catch (Throwable) {
            return BigDecimal::zero();
        }
    }

    public static function add(int|float|string|null $a, int|float|string|null $b): string
    {
        return (string) self::of($a)->plus(self::of($b));
    }

    public static function sub(int|float|string|null $a, int|float|string|null $b): string
    {
        return (string) self::of($a)->minus(self::of($b));
    }

    /**
     * Nhân rồi làm tròn (mặc định tới 2 chữ số, làm tròn nửa lên).
     */
    public static function mul(int|float|string|null $a, int|float|string|null $b, int $scale = self::MONEY_SCALE): string
    {
        return (string) self::of($a)->multipliedBy(self::of($b))->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Chia rồi làm tròn; chia cho 0 trả về 0 thay vì ném lỗi.
     */
    public static function div(int|float|string|null $a, int|float|string|null $b, int $scale = self::MONEY_SCALE): string
    {
        $divisor = self::of($b);

        if ($divisor->isZero()) {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        return (string) self::of($a)->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    public static function round(int|float|string|null $value, int $scale = self::MONEY_SCALE): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * $rate phần trăm của $amount: percentOf('1000000', '10') = '100000.00'.
     */
    public static function percentOf(int|float|string|null $amount, int|float|string|null $rate, int $scale = self::MONEY_SCALE): string
    {
        return (string) self::of($amount)
            ->multipliedBy(self::of($rate))
            ->dividedBy(100, $scale, RoundingMode::HalfUp);
    }

    /**
     * $part chiếm bao nhiêu phần trăm của $whole: ratio('20', '80') = '25.00'.
     */
    public static function ratio(int|float|string|null $part, int|float|string|null $whole, int $scale = 2): string
    {
        $whole = self::of($whole);

        if ($whole->isZero()) {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        return (string) self::of($part)
            ->multipliedBy(100)
            ->dividedBy($whole, $scale, RoundingMode::HalfUp);
    }

    /**
     * -1 nếu $a < $b, 0 nếu bằng, 1 nếu $a > $b.
     */
    public static function compare(int|float|string|null $a, int|float|string|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    /**
     * Giới hạn giá trị trong khoảng [$min, $max].
     */
    public static function clamp(int|float|string|null $value, int|float|string $min, int|float|string $max): string
    {
        if (self::compare($value, $min) < 0) {
            return (string) self::of($min);
        }

        if (self::compare($value, $max) > 0) {
            return (string) self::of($max);
        }

        return (string) self::of($value);
    }

    public static function isZero(int|float|string|null $value): bool
    {
        return self::of($value)->isZero();
    }

    public static function isPositive(int|float|string|null $value): bool
    {
        return self::compare($value, 0) > 0;
    }

    /**
     * Bỏ số 0 thừa để hiển thị trong ô nhập: "1500000.00" → "1500000",
     * "2.50" → "2.5". Giá trị rỗng giữ nguyên là null.
     */
    public static function trimZeros(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decimal = self::of($value);

        if ($decimal->isZero()) {
            return '0';
        }

        $text = (string) $decimal;

        if (! str_contains($text, '.')) {
            return $text;
        }

        return rtrim(rtrim($text, '0'), '.');
    }
}
