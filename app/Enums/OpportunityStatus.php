<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Các bước của một cơ hội kinh doanh (mục 10 của yêu cầu).
 */
enum OpportunityStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Mới',
            self::Contacted => 'Đã liên hệ',
            self::Proposal => 'Đã gửi đề xuất',
            self::Negotiation => 'Đàm phán',
            self::Won => 'Thắng',
            self::Lost => 'Thua',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Contacted => 'info',
            self::Proposal => 'primary',
            self::Negotiation => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    /**
     * Cơ hội còn đang theo đuổi (chưa thắng, chưa thua).
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Won, self::Lost], true);
    }

    /**
     * @return list<self>
     */
    public static function openCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isOpen()));
    }
}
