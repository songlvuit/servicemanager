<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái biên bản nghiệm thu (mục 29).
 */
enum AcceptanceStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Submitted => 'Chờ duyệt',
            self::Approved => 'Đã nghiệm thu',
            self::Rejected => 'Bị từ chối',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
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
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [],
            self::Rejected => [self::Draft],
        };
    }
}
