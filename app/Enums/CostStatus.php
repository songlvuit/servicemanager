<?php

namespace App\Enums;

use App\Support\Decimal;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Tình trạng chi phí so với ngân sách (mục 19, Rule 6–7).
 */
enum CostStatus: string implements HasColor, HasLabel
{
    case NoBudget = 'no_budget';
    case Ok = 'ok';
    case Warning = 'warning';
    case Over = 'over';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoBudget => 'Chưa có ngân sách',
            self::Ok => 'Trong ngân sách',
            self::Warning => 'Cảnh báo',
            self::Over => 'Vượt ngân sách',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NoBudget => 'gray',
            self::Ok => 'success',
            self::Warning => 'warning',
            self::Over => 'danger',
        };
    }

    /**
     * Rule 7: chi phí thực tế > ngân sách → vượt ngân sách.
     * Rule 6: chi phí thực tế ≥ ngưỡng cảnh báo (mặc định 80%) → cảnh báo.
     */
    public static function for(int|float|string|null $budget, int|float|string|null $actual): self
    {
        if (! Decimal::isPositive($budget)) {
            return Decimal::isPositive($actual) ? self::Over : self::NoBudget;
        }

        if (Decimal::compare($actual, $budget) > 0) {
            return self::Over;
        }

        $warningLevel = Decimal::percentOf($budget, config('job.budget_warning_threshold', 80));

        return Decimal::compare($actual, $warningLevel) >= 0 ? self::Warning : self::Ok;
    }

    public function isAlert(): bool
    {
        return in_array($this, [self::Warning, self::Over], true);
    }

    /**
     * Dòng cảnh báo hiển thị trên trang công việc.
     */
    public function message(): ?string
    {
        return match ($this) {
            self::Over => '🔴 Vượt ngân sách: chi phí thực tế đã lớn hơn ngân sách.',
            self::Warning => '🟠 Cảnh báo: chi phí thực tế đã đạt từ '.rtrim(rtrim(number_format((float) config('job.budget_warning_threshold', 80), 2, ',', '.'), '0'), ',').'% ngân sách.',
            default => null,
        };
    }
}
