<?php

/*
|--------------------------------------------------------------------------
| Hóa đơn & công nợ
|--------------------------------------------------------------------------
|
| Có thể đổi trong màn hình Hệ thống → Cấu hình (giá trị lưu trong database
| sẽ ghi đè giá trị mặc định ở đây).
|
*/

return [

    // Hạn thanh toán mặc định của hóa đơn: số ngày kể từ ngày hóa đơn.
    'payment_term_days' => (int) env('INVOICE_PAYMENT_TERM_DAYS', 30),

    // Thuế suất GTGT mặc định của hóa đơn (%).
    'default_tax_rate' => (float) env('INVOICE_DEFAULT_TAX_RATE', 10),

    // Nhắc trước khi hóa đơn đến hạn bao nhiêu ngày.
    'due_reminder_days' => (int) env('INVOICE_DUE_REMINDER_DAYS', 7),
];
