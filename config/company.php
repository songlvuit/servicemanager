<?php

/*
|--------------------------------------------------------------------------
| Thông tin công ty
|--------------------------------------------------------------------------
|
| Sửa trong màn hình Hệ thống → Cấu hình (giá trị lưu trong database sẽ ghi
| đè giá trị mặc định ở đây).
|
*/

return [
    'name' => env('COMPANY_NAME', env('APP_NAME', 'Service Manager')),
    'tax_code' => env('COMPANY_TAX_CODE'),
    'address' => env('COMPANY_ADDRESS'),
    'phone' => env('COMPANY_PHONE'),
    'email' => env('COMPANY_EMAIL'),
];
