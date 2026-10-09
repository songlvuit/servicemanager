<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái dự án (mục 16).
 */
enum ProjectStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Planning = 'planning';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planning => 'Lập kế hoạch',
            self::Active => 'Đang thực hiện',
            self::OnHold => 'Tạm dừng',
            self::Completed => 'Hoàn thành',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planning => 'gray',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Completed => 'info',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Các trạng thái có thể chuyển tới từ trạng thái hiện tại.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planning => [self::Active, self::Cancelled],
            self::Active => [self::OnHold, self::Completed, self::Cancelled],
            self::OnHold => [self::Active, self::Cancelled],
            self::Completed => [self::Active],
            self::Cancelled => [],
        };
    }
}
