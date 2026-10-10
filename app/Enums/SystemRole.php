<?php

namespace App\Enums;

/**
 * Các vai trò mặc định của hệ thống. Không thể đổi tên hoặc xoá các vai trò
 * này; có thể tạo thêm vai trò khác trong màn hình "Vai trò & quyền".
 */
enum SystemRole: string
{
    case SuperAdmin = 'Super Admin';
    case Director = 'Director';
    case Sales = 'Sales';
    case ProjectManager = 'Project Manager';
    case Accountant = 'Accountant';
    case Procurement = 'Procurement';
    case Employee = 'Employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Quản trị hệ thống',
            self::Director => 'Giám đốc',
            self::Sales => 'Kinh doanh',
            self::ProjectManager => 'Quản lý công việc',
            self::Accountant => 'Kế toán',
            self::Procurement => 'Mua hàng',
            self::Employee => 'Nhân viên',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Toàn quyền trên hệ thống.',
            self::Director => 'Xem toàn bộ hệ thống, duyệt các khoản quan trọng, xem tài chính và lợi nhuận.',
            self::Sales => 'Quản lý khách hàng và cơ hội.',
            self::ProjectManager => 'Quản lý công việc, lịch làm việc, chấm công, đề nghị chi, mua hàng.',
            self::Accountant => 'Quản lý báo giá, hợp đồng, nghiệm thu, hóa đơn, thanh toán, công nợ, chi phí.',
            self::Procurement => 'Quản lý nhà cung cấp, yêu cầu mua hàng, đơn mua hàng.',
            self::Employee => 'Xem công việc được giao, cập nhật công việc, chấm công, tạo đề nghị chi.',
        };
    }

    public static function isSystem(?string $name): bool
    {
        return $name !== null && self::tryFrom($name) !== null;
    }

    /**
     * Tên hiển thị của một vai trò bất kỳ: vai trò hệ thống có nhãn tiếng
     * Việt, vai trò tự tạo dùng chính tên của nó.
     */
    public static function labelFor(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return self::tryFrom($name)?->label() ?? $name;
    }
}
