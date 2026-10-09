<?php

/*
|--------------------------------------------------------------------------
| Quy tắc nghiệp vụ của báo giá
|--------------------------------------------------------------------------
|
| Các ngưỡng dưới đây không viết cứng trong code; đổi bằng biến môi trường
| trong .env (nhớ khởi động lại container app ở production).
|
*/

return [

    // Số ngày hiệu lực mặc định của một báo giá mới.
    'validity_days' => (int) env('QUOTATION_VALIDITY_DAYS', 30),

    // Thuế suất VAT mặc định (%) cho mỗi hạng mục.
    'default_tax_rate' => (float) env('QUOTATION_DEFAULT_TAX_RATE', 10),

    // Biên lợi nhuận (%) thấp hơn mức này thì cảnh báo "Biên lợi nhuận thấp."
    'low_margin_threshold' => (float) env('QUOTATION_LOW_MARGIN_THRESHOLD', 20),

    // Tỷ lệ chiết khấu (%) vượt mức này thì bắt buộc Giám đốc duyệt.
    'director_discount_threshold' => (float) env('QUOTATION_DIRECTOR_DISCOUNT_THRESHOLD', 20),

    /*
    | Cơ sở tính lợi nhuận ước tính:
    |   "subtotal" — giá trị trước thuế (mặc định). VAT là khoản thu hộ nên
    |                không tính vào doanh thu khi tính biên lợi nhuận.
    |   "total"    — giá trị sau thuế, đúng nguyên văn công thức trong tài liệu
    |                yêu cầu (total - estimated_cost).
    */
    'profit_base' => env('QUOTATION_PROFIT_BASE', 'subtotal'),
];
