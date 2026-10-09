<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái yêu cầu mua hàng (mục 24).
 */
enum PurchaseRequestStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ordered = 'ordered';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Submitted => 'Chờ duyệt',
            self::Approved => 'Đã duyệt',
            self::Rejected => 'Bị từ chối',
            self::Ordered => 'Đã đặt hàng',
            self::Received => 'Đã nhận hàng',
            self::Completed => 'Hoàn tất',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Ordered => 'info',
            self::Received => 'info',
            self::Completed => 'success',
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
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Ordered, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Ordered => [self::Received, self::Approved],
            self::Received => [self::Completed],
            self::Completed => [],
            self::Cancelled => [],
        };
    }
}
