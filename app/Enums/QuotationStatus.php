<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái báo giá và các bước chuyển hợp lệ (mục 11, 14 của yêu cầu).
 *
 *   Nháp → Chờ duyệt → Đã duyệt → Đã gửi khách → Khách chấp nhận
 *                 ↘ Bị từ chối ↗ (soạn lại về Nháp)
 */
enum QuotationStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::PendingApproval => 'Chờ duyệt',
            self::Approved => 'Đã duyệt',
            self::Sent => 'Đã gửi khách',
            self::Accepted => 'Khách chấp nhận',
            self::Rejected => 'Bị từ chối',
            self::Expired => 'Hết hạn',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Approved => 'success',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Expired => 'gray',
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
            self::Draft => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Sent, self::Expired, self::Cancelled],
            self::Sent => [self::Accepted, self::Rejected, self::Expired, self::Cancelled],
            self::Rejected, self::Expired => [self::Draft],
            self::Accepted, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Chỉ báo giá nháp mới được sửa nội dung và hạng mục.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Báo giá chưa có giá trị ràng buộc nên được phép xoá (xoá mềm).
     */
    public function isDeletable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Expired, self::Cancelled], true);
    }
}
