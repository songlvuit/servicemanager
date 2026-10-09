<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Trạng thái đề nghị chi (mục 20). Các trạng thái đang duyệt phản ánh bước duyệt hiện tại của quy trình duyệt.
 */
enum ExpenseStatus: string implements HasColor, HasLabel
{
    use HasTransitions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case PmApproved = 'pm_approved';
    case AccountingReview = 'accounting_review';
    case DirectorApproval = 'director_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Submitted => 'Chờ PM duyệt',
            self::PmApproved => 'PM đã duyệt',
            self::AccountingReview => 'Kế toán kiểm tra',
            self::DirectorApproval => 'Chờ Giám đốc duyệt',
            self::Approved => 'Đã duyệt',
            self::Rejected => 'Bị từ chối',
            self::Paid => 'Đã thanh toán',
            self::Completed => 'Hoàn tất',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::PmApproved => 'warning',
            self::AccountingReview => 'warning',
            self::DirectorApproval => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Paid => 'info',
            self::Completed => 'success',
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
            self::Draft => [self::Submitted, self::PmApproved, self::AccountingReview, self::DirectorApproval],
            self::Submitted => [self::PmApproved, self::AccountingReview, self::DirectorApproval, self::Approved, self::Rejected],
            self::PmApproved => [self::AccountingReview, self::DirectorApproval, self::Approved, self::Rejected],
            self::AccountingReview => [self::PmApproved, self::DirectorApproval, self::Approved, self::Rejected],
            self::DirectorApproval => [self::Approved, self::Rejected],
            self::Approved => [self::Paid],
            self::Rejected => [self::Draft],
            self::Paid => [self::Completed],
            self::Completed => [],
        };
    }

    /**
     * Đang nằm trong quy trình duyệt (chưa có kết quả cuối).
     */
    public function isInApproval(): bool
    {
        return in_array($this, self::inApproval(), true);
    }

    /**
     * @return list<self>
     */
    public static function inApproval(): array
    {
        return [self::Submitted, self::PmApproved, self::AccountingReview, self::DirectorApproval];
    }

    /**
     * Trạng thái hiển thị khi quy trình đang chờ vai trò $role duyệt.
     * $isFirstStep: bước chờ này là bước đầu tiên của quy trình.
     */
    public static function awaiting(string $role, bool $isFirstStep): self
    {
        return match ($role) {
            SystemRole::ProjectManager->value => self::Submitted,
            SystemRole::Accountant->value => self::AccountingReview,
            SystemRole::Director->value => self::DirectorApproval,
            // Vai trò tự tạo (ví dụ "Manager"): chưa qua bước nào thì "Chờ duyệt", còn lại là đang duyệt tiếp.
            default => $isFirstStep ? self::Submitted : self::PmApproved,
        };
    }

    /**
     * Đã được duyệt xong: tính vào chi phí cam kết của dự án.
     */
    public function isCommitted(): bool
    {
        return in_array($this, [self::Approved, self::Paid, self::Completed], true);
    }

    /**
     * Đã chi tiền: tính vào chi phí thực tế của dự án.
     */
    public function isActual(): bool
    {
        return in_array($this, [self::Paid, self::Completed], true);
    }
}
