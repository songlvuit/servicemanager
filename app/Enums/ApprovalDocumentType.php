<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Loại chứng từ chạy qua quy trình duyệt cấu hình được (mục 21, 61).
 */
enum ApprovalDocumentType: string implements HasColor, HasLabel
{
    case Expense = 'expense';
    case PurchaseRequest = 'purchase_request';

    public function getLabel(): string
    {
        return match ($this) {
            self::Expense => 'Đề nghị chi',
            self::PurchaseRequest => 'Yêu cầu mua hàng',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Expense => 'primary',
            self::PurchaseRequest => 'info',
        };
    }
}
