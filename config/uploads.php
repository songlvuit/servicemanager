<?php

/*
|--------------------------------------------------------------------------
| Tài liệu đính kèm (mục 47, 53)
|--------------------------------------------------------------------------
|
| Khi tăng dung lượng tối đa, sửa cả upload_max_filesize / post_max_size trong
| docker/php/php.ini và client_max_body_size trong docker/nginx/default.conf.
|
*/

return [

    // File được lưu ở disk riêng tư và chỉ tải được qua route có kiểm tra quyền.
    'disk' => env('UPLOADS_DISK', 'local'),

    'max_size_mb' => (int) env('UPLOADS_MAX_SIZE_MB', 20),

    // Định dạng cho phép: pdf, jpg, jpeg, png, doc, docx, xls, xlsx.
    'mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],

    'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
];
