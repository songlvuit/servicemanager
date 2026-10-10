<?php

/*
|--------------------------------------------------------------------------
| Quy tắc kiểm soát chi phí của công việc và lợi nhuận của hợp đồng
|--------------------------------------------------------------------------
|
| Các ngưỡng dưới đây có thể đổi trong màn hình Hệ thống → Cấu hình (giá trị
| lưu trong database sẽ ghi đè giá trị mặc định ở đây). Tên biến môi trường
| PROJECT_* của bản cũ vẫn được đọc để file .env đang chạy không phải sửa.
|
*/

return [

    // Rule 6: cảnh báo khi chi phí thực tế của công việc đạt từ X% ngân sách.
    'budget_warning_threshold' => (float) env('JOB_BUDGET_WARNING_THRESHOLD', env('PROJECT_BUDGET_WARNING_THRESHOLD', 80)),

    // Cảnh báo hợp đồng sắp hết hạn trước bao nhiêu ngày.
    'contract_expiry_warning_days' => (int) env('CONTRACT_EXPIRY_WARNING_DAYS', 30),

    // Đánh giá biên lợi nhuận của hợp đồng (mục 37): trên 'margin_good' là tốt,
    // dưới 'margin_warning' là cảnh báo, dưới 'margin_danger' là nguy hiểm.
    'margin_good' => (float) env('JOB_MARGIN_GOOD', env('PROJECT_MARGIN_GOOD', 30)),
    'margin_warning' => (float) env('JOB_MARGIN_WARNING', env('PROJECT_MARGIN_WARNING', 20)),
    'margin_danger' => (float) env('JOB_MARGIN_DANGER', env('PROJECT_MARGIN_DANGER', 10)),
];
