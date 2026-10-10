<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái đơn mua hàng (mục 25).
 */
enum PurchaseOrderStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
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
            self::Ordered => 'warning',
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
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::Received, self::Cancelled],
            self::Received => [self::Completed],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    /**
     * Đã đặt hàng: tính vào chi phí cam kết của công việc.
     */
    public function isCommitted(): bool
    {
        return in_array($this, [self::Ordered, self::Received, self::Completed], true);
    }

    /**
     * Đã nhận hàng: tính vào chi phí thực tế của công việc.
     */
    public function isActual(): bool
    {
        return in_array($this, [self::Received, self::Completed], true);
    }
}
