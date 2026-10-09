<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái của một lịch làm việc.
 */
enum ScheduleStatus: string implements HasColor, HasLabel
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tentative => 'Dự kiến',
            self::Confirmed => 'Đã chốt',
            self::Done => 'Đã xong',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Tentative => 'warning',
            self::Confirmed => 'info',
            self::Done => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Lịch đã huỷ không giữ chỗ của ai: không tính khi kiểm tra trùng lịch.
     */
    public function occupiesTime(): bool
    {
        return $this !== self::Cancelled;
    }
}
