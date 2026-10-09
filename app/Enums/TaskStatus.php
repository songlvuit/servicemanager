<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái công việc (mục 26).
 */
enum TaskStatus: string implements HasColor, HasLabel
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Todo => 'Cần làm',
            self::InProgress => 'Đang làm',
            self::Review => 'Chờ kiểm tra',
            self::Done => 'Hoàn thành',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'info',
            self::Review => 'warning',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Các cột của bảng Kanban, theo thứ tự hiển thị (mục 27).
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        return [self::Todo, self::InProgress, self::Review, self::Done];
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Cancelled], true);
    }
}
