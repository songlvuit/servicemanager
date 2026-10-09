<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái khách hàng (mục 8 của yêu cầu).
 */
enum CustomerStatus: string implements HasColor, HasLabel
{
    case Lead = 'lead';
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lead => 'Tiềm năng',
            self::Active => 'Đang hoạt động',
            self::Inactive => 'Ngừng hoạt động',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'info',
            self::Active => 'success',
            self::Inactive => 'gray',
        };
    }
}
