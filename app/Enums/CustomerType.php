<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Loại hình khách hàng.
 */
enum CustomerType: string implements HasColor, HasLabel
{
    case Company = 'company';
    case Individual = 'individual';
    case Government = 'government';
    case Organization = 'organization';

    public function getLabel(): string
    {
        return match ($this) {
            self::Company => 'Doanh nghiệp',
            self::Individual => 'Cá nhân',
            self::Government => 'Cơ quan nhà nước',
            self::Organization => 'Tổ chức khác',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Company => 'primary',
            self::Individual => 'info',
            self::Government => 'warning',
            self::Organization => 'gray',
        };
    }
}
