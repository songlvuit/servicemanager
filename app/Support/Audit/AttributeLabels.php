<?php

namespace App\Support\Audit;

/**
 * Tên tiếng Việt của các cột hay gặp, dùng trong mô tả của nhật ký thao tác
 * ("Cập nhật: Điện thoại, Địa chỉ"). Cột chưa khai báo thì hiện tên cột.
 */
class AttributeLabels
{
    private const LABELS = [
        'name' => 'Tên',
        'title' => 'Tiêu đề',
        'code' => 'Mã',
        'description' => 'Mô tả',
        'notes' => 'Ghi chú',
        'status' => 'Trạng thái',
        'email' => 'Email',
        'phone' => 'Điện thoại',
        'address' => 'Địa chỉ',
        'province' => 'Tỉnh / Thành phố',
        'website' => 'Website',
        'tax_code' => 'Mã số thuế',
        'industry' => 'Ngành nghề',
        'company_type' => 'Loại hình',
        'position' => 'Chức danh',
        'department' => 'Phòng ban',
        'is_primary' => 'Liên hệ chính',
        'is_active' => 'Đang hoạt động',
        'password' => 'Mật khẩu',
        'hourly_rate' => 'Đơn giá giờ công',
        'customer_id' => 'Khách hàng',
        'contract_id' => 'Hợp đồng',
        'quotation_id' => 'Báo giá',
        'opportunity_id' => 'Cơ hội',
        'task_id' => 'Công việc',
        'supplier_id' => 'Nhà cung cấp',
        'vendor_id' => 'Nhà cung cấp',
        'sales_owner_id' => 'Người phụ trách',
        'employee_id' => 'Người đề nghị',
        'category' => 'Nhóm',
        'amount' => 'Số tiền',
        'tax' => 'Thuế',
        'tax_rate' => 'Thuế suất',
        'tax_amount' => 'Tiền thuế',
        'subtotal' => 'Thành tiền trước thuế',
        'discount' => 'Chiết khấu',
        'total' => 'Tổng cộng',
        'total_amount' => 'Tổng tiền',
        'total_value' => 'Tổng giá trị',
        'contract_value' => 'Giá trị hợp đồng',
        'estimated_value' => 'Giá trị ước tính',
        'estimated_cost' => 'Giá vốn ước tính',
        'estimated_profit' => 'Lợi nhuận ước tính',
        'estimated_margin' => 'Biên lợi nhuận ước tính',
        'planned_amount' => 'Ngân sách',
        'paid_amount' => 'Đã thu',
        'remaining_amount' => 'Còn phải thu',
        'probability' => 'Xác suất',
        'default_price' => 'Đơn giá bán',
        'default_cost' => 'Giá vốn',
        'default_unit' => 'Đơn vị tính',
        'min_amount' => 'Từ số tiền',
        'max_amount' => 'Đến số tiền',
        'required_role' => 'Vai trò duyệt',
        'sequence' => 'Thứ tự',
        'payment_method' => 'Hình thức thanh toán',
        'payment_terms' => 'Điều khoản thanh toán',
        'payment_reference' => 'Số chứng từ thanh toán',
        'reference' => 'Số chứng từ',
        'bank_name' => 'Ngân hàng',
        'bank_account' => 'Số tài khoản',
        'contact_name' => 'Người liên hệ',
        'drive_url' => 'Google Drive',
        'quotation_date' => 'Ngày báo giá',
        'valid_until' => 'Hiệu lực đến',
        'sign_date' => 'Ngày ký',
        'start_date' => 'Ngày bắt đầu',
        'end_date' => 'Ngày kết thúc',
        'expense_date' => 'Ngày chi',
        'needed_date' => 'Ngày cần hàng',
        'order_date' => 'Ngày đặt hàng',
        'delivery_date' => 'Ngày giao hàng',
        'acceptance_date' => 'Ngày nghiệm thu',
        'invoice_date' => 'Ngày hóa đơn',
        'due_date' => 'Hạn thanh toán',
        'payment_date' => 'Ngày thanh toán',
        'expected_close_date' => 'Dự kiến chốt',
        'submitted_at' => 'Thời điểm gửi duyệt',
        'approved_at' => 'Thời điểm duyệt',
        'approved_by' => 'Người duyệt',
        'approval_note' => 'Ghi chú duyệt',
        'rejection_reason' => 'Lý do từ chối',
        'cancel_reason' => 'Lý do huỷ',
        'requires_director_approval' => 'Cần Giám đốc duyệt',
        'sent_at' => 'Thời điểm gửi khách',
        'accepted_at' => 'Thời điểm khách chấp nhận',
        'activated_at' => 'Thời điểm có hiệu lực',
        'activated_by' => 'Người kích hoạt',
        'issued_at' => 'Thời điểm phát hành',
        'paid_at' => 'Thời điểm thanh toán',
        'paid_by' => 'Người thanh toán',
        'completed_at' => 'Thời điểm hoàn tất',
        'ordered_at' => 'Thời điểm đặt hàng',
        'received_at' => 'Thời điểm nhận hàng',
        'filename' => 'Tên file',
    ];

    public static function for(string $attribute): string
    {
        return self::LABELS[$attribute] ?? $attribute;
    }

    /**
     * Giá trị của một trường dưới dạng chữ để hiển thị.
     */
    public static function display(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Có' : 'Không',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
