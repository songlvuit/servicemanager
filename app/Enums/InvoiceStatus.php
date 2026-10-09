<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái hóa đơn (mục 30).
 */
enum InvoiceStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Issued => 'Đã phát hành',
            self::PartiallyPaid => 'Thu một phần',
            self::Paid => 'Đã thu đủ',
            self::Overdue => 'Quá hạn',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Cancelled => 'gray',
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
            self::Draft => [self::Issued, self::Overdue, self::Cancelled],
            self::Issued => [self::PartiallyPaid, self::Paid, self::Overdue, self::Cancelled],
            self::PartiallyPaid => [self::Paid, self::Overdue],
            self::Paid => [],
            self::Overdue => [self::Paid, self::Cancelled],
            self::Cancelled => [],
        };
    }

    /**
     * Đã phát hành và còn phải thu tiền.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Issued, self::PartiallyPaid, self::Overdue];
    }

    /**
     * Các trạng thái được tính là doanh thu / công nợ (đã phát hành, không tính nháp và đã huỷ).
     *
     * @return list<self>
     */
    public static function billed(): array
    {
        return [self::Issued, self::PartiallyPaid, self::Paid, self::Overdue];
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(fn (self $status): string => $status->value, $statuses);
    }
}
