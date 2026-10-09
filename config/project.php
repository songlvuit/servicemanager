<?php

/*
|--------------------------------------------------------------------------
| Quy tắc nghiệp vụ của dự án & kiểm soát chi phí
|--------------------------------------------------------------------------
|
| Các ngưỡng dưới đây có thể đổi trong màn hình Hệ thống → Cấu hình (giá trị
| lưu trong database sẽ ghi đè giá trị mặc định ở đây).
|
*/

return [

    // Rule 6: cảnh báo khi chi phí thực tế đạt từ X% ngân sách.
    'budget_warning_threshold' => (float) env('PROJECT_BUDGET_WARNING_THRESHOLD', 80),

    // Cảnh báo hợp đồng sắp hết hạn / dự án sắp tới hạn trước bao nhiêu ngày.
    'contract_expiry_warning_days' => (int) env('CONTRACT_EXPIRY_WARNING_DAYS', 30),
    'deadline_warning_days' => (int) env('PROJECT_DEADLINE_WARNING_DAYS', 7),

    // Đánh giá biên lợi nhuận (mục 37): trên 'margin_good' là tốt, dưới
    // 'margin_warning' là cảnh báo, dưới 'margin_danger' là nguy hiểm.
    'margin_good' => (float) env('PROJECT_MARGIN_GOOD', 30),
    'margin_warning' => (float) env('PROJECT_MARGIN_WARNING', 20),
    'margin_danger' => (float) env('PROJECT_MARGIN_DANGER', 10),
];
