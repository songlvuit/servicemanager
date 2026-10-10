<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nhóm ngân sách / chi phí của công việc (mục 18).
 */
enum BudgetCategory: string implements HasColor, HasLabel
{
    case Personnel = 'personnel';
    case Equipment = 'equipment';
    case Supplier = 'supplier';
    case Travel = 'travel';
    case Production = 'production';
    case Software = 'software';
    case Marketing = 'marketing';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Personnel => 'Nhân sự',
            self::Equipment => 'Thiết bị',
            self::Supplier => 'Nhà cung cấp',
            self::Travel => 'Đi lại',
            self::Production => 'Sản xuất',
            self::Software => 'Phần mềm',
            self::Marketing => 'Marketing',
            self::Other => 'Khác',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Personnel => 'primary',
            self::Equipment => 'info',
            self::Supplier => 'warning',
            self::Travel => 'gray',
            self::Production => 'success',
            self::Software => 'info',
            self::Marketing => 'primary',
            self::Other => 'gray',
        };
    }
}
