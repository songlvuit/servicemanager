<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Hình thức thanh toán (mục 31).
 */
enum PaymentMethod: string implements HasColor, HasLabel
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::BankTransfer => 'Chuyển khoản',
            self::Cash => 'Tiền mặt',
            self::Card => 'Thẻ',
            self::Other => 'Khác',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BankTransfer => 'info',
            self::Cash => 'success',
            self::Card => 'primary',
            self::Other => 'gray',
        };
    }
}
