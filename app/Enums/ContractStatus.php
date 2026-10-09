<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái hợp đồng (mục 15).
 */
enum ContractStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case PendingSignature = 'pending_signature';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::PendingSignature => 'Chờ ký',
            self::Active => 'Đang hiệu lực',
            self::Completed => 'Đã hoàn thành',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingSignature => 'warning',
            self::Active => 'success',
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
            self::Draft => [self::PendingSignature, self::Cancelled],
            self::PendingSignature => [self::Active, self::Draft, self::Cancelled],
            self::Active => [self::Completed, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }
}
