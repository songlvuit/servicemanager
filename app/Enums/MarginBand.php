<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Đánh giá biên lợi nhuận của hợp đồng (mục 37). Các ngưỡng nằm trong
 * config/project.php và sửa được ở Hệ thống → Cấu hình:
 *
 *   > 30%   tốt
 *   20–30%  bình thường
 *   < 20%   cảnh báo
 *   < 10%   nguy hiểm
 */
enum MarginBand: string implements HasColor, HasLabel
{
    case Good = 'good';
    case Normal = 'normal';
    case Warning = 'warning';
    case Danger = 'danger';

    public static function for(int|float|string|null $margin): self
    {
        $margin = (float) $margin;

        return match (true) {
            $margin < (float) config('job.margin_danger', 10) => self::Danger,
            $margin < (float) config('job.margin_warning', 20) => self::Warning,
            $margin <= (float) config('job.margin_good', 30) => self::Normal,
            default => self::Good,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Good => 'Tốt',
            self::Normal => 'Bình thường',
            self::Warning => 'Cảnh báo',
            self::Danger => 'Nguy hiểm',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Good => 'success',
            self::Normal => 'gray',
            self::Warning => 'warning',
            self::Danger => 'danger',
        };
    }
}
