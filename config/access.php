<?php

/*
|--------------------------------------------------------------------------
| Phân quyền theo module
|--------------------------------------------------------------------------
|
| Nguồn duy nhất mô tả các module của hệ thống: nhãn hiển thị, vị trí trên
| sidebar, phase triển khai và các quyền (ability) của module.
|
| Tên quyền lưu trong database có dạng "{module}.{ability}",
| ví dụ: customers.view, quotations.approve.
|
| Sau khi sửa file này, chạy:
|   php artisan db:seed --class=RolesAndPermissionsSeeder --force
| (container app tự chạy lệnh này mỗi lần khởi động).
|
*/

return [

    'guard' => 'web',

    // Phase đã triển khai xong. Module có 'phase' lớn hơn số này sẽ hiện trên
    // sidebar dưới dạng trang "đang phát triển"; tăng số này sau mỗi phase.
    'completed_phase' => 6,

    // Nhãn tiếng Việt của từng ability.
    'abilities' => [
        'view' => 'Xem',
        'view_all' => 'Xem tất cả (không chỉ của mình)',
        'create' => 'Tạo',
        'update' => 'Sửa',
        'delete' => 'Xoá',
        'approve' => 'Duyệt',
        'pay' => 'Thanh toán',
        'export' => 'Xuất dữ liệu',
    ],

    // Thứ tự các nhóm trên sidebar.
    'groups' => [
        'Tổng quan',
        'Kinh doanh',
        'Vận hành',
        'Tài chính',
        'Hệ thống',
    ],

    /*
    | menu  : có hiện trên sidebar hay không.
    | phase : phase mà module được triển khai (module chưa làm sẽ hiện trang
    |         "đang phát triển").
    | abilities = [] : không cần quyền, mọi người dùng đều thấy.
    */
    'modules' => [

        // --- Tổng quan ----------------------------------------------------
        // Lịch chung của công ty: MỌI người dùng đều xem được (không có quyền
        // "Xem"); các quyền dưới đây là để xếp lịch. Ai cũng tự đăng ký được
        // lịch nghỉ của chính mình.
        'schedules' => [
            'label' => 'Lịch làm việc',
            'group' => 'Tổng quan',
            'icon' => 'heroicon-o-calendar-days',
            'sort' => 20,
            'phase' => 6,
            'menu' => true,
            'abilities' => ['create', 'update', 'delete'],
        ],

        // --- Kinh doanh ---------------------------------------------------
        'customers' => [
            'label' => 'Khách hàng',
            'group' => 'Kinh doanh',
            'icon' => 'heroicon-o-building-office-2',
            'sort' => 10,
            'phase' => 2,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete', 'export'],
        ],
        'opportunities' => [
            'label' => 'Cơ hội',
            'group' => 'Kinh doanh',
            'icon' => 'heroicon-o-sparkles',
            'sort' => 20,
            'phase' => 2,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],
        'quotations' => [
            'label' => 'Báo giá',
            'group' => 'Kinh doanh',
            'icon' => 'heroicon-o-document-text',
            'sort' => 30,
            'phase' => 2,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete', 'approve', 'export'],
        ],
        'contracts' => [
            'label' => 'Hợp đồng',
            'group' => 'Kinh doanh',
            'icon' => 'heroicon-o-document-check',
            'sort' => 40,
            'phase' => 3,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete', 'approve'],
        ],

        'services' => [
            'label' => 'Dịch vụ',
            'group' => 'Kinh doanh',
            'icon' => 'heroicon-o-squares-2x2',
            'sort' => 50,
            'phase' => 2,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],

        // --- Vận hành: công việc và chi phí của công việc ---------------------
        'tasks' => [
            'label' => 'Công việc',
            'group' => 'Vận hành',
            'icon' => 'heroicon-o-rectangle-stack',
            'sort' => 20,
            'phase' => 3,
            'menu' => true,
            // view_all: xem mọi công việc; không có thì chỉ thấy việc được giao hoặc mình có trong ê-kíp.
            // Hợp đồng / báo giá gắn với công việc chỉ hiện với người có quyền xem Hợp đồng / Báo giá.
            'abilities' => ['view', 'view_all', 'create', 'update', 'delete', 'export'],
        ],
        'timesheets' => [
            'label' => 'Chấm công',
            'group' => 'Vận hành',
            'icon' => 'heroicon-o-clock',
            'sort' => 25,
            'phase' => 4,
            'menu' => true,
            // view_all: xem và ghi giờ công của mọi người; không có thì chỉ của mình.
            'abilities' => ['view', 'view_all', 'create', 'update', 'delete', 'export'],
        ],
        'expenses' => [
            'label' => 'Chi phí',
            'group' => 'Vận hành',
            'icon' => 'heroicon-o-banknotes',
            'sort' => 40,
            'phase' => 4,
            'menu' => true,
            // view_all: xem mọi đề nghị chi; không có thì chỉ của mình và của công việc mình phụ trách.
            // Ai được duyệt đề nghị chi do Hệ thống → Quy trình duyệt quyết định (theo vai trò),
            // nên module này không có quyền "Duyệt".
            'abilities' => ['view', 'view_all', 'create', 'update', 'delete', 'pay', 'export'],
        ],
        'purchases' => [
            'label' => 'Mua hàng',
            'group' => 'Vận hành',
            'icon' => 'heroicon-o-shopping-cart',
            'sort' => 50,
            'phase' => 4,
            'menu' => true,
            // approve: đặt hàng theo đơn lập trực tiếp (không qua yêu cầu mua hàng đã duyệt).
            // Việc duyệt yêu cầu mua hàng do Hệ thống → Quy trình duyệt quyết định.
            'abilities' => ['view', 'create', 'update', 'delete', 'approve'],
        ],
        'suppliers' => [
            'label' => 'Nhà cung cấp',
            'group' => 'Vận hành',
            'icon' => 'heroicon-o-truck',
            'sort' => 60,
            'phase' => 4,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],
        // --- Tài chính ----------------------------------------------------
        // Nghiệm thu, hóa đơn, công nợ, thanh toán đều theo HỢP ĐỒNG.
        'acceptances' => [
            'label' => 'Nghiệm thu',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-clipboard-document-check',
            'sort' => 5,
            'phase' => 5,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete', 'approve'],
        ],
        'invoices' => [
            'label' => 'Hóa đơn',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-receipt-percent',
            'sort' => 10,
            'phase' => 5,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete', 'export'],
        ],
        'receivables' => [
            'label' => 'Công nợ',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-scale',
            'sort' => 20,
            'phase' => 5,
            'menu' => true,
            'abilities' => ['view', 'export'],
        ],
        'payments' => [
            'label' => 'Thanh toán',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-credit-card',
            'sort' => 30,
            'phase' => 5,
            'menu' => true,
            'abilities' => ['view', 'create', 'export'],
        ],
        // Báo cáo lợi nhuận, doanh thu, chi phí và các biểu đồ tài chính trên dashboard.
        'profit_reports' => [
            'label' => 'Báo cáo tài chính',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-chart-bar',
            'sort' => 40,
            'phase' => 6,
            'menu' => true,
            'abilities' => ['view', 'export'],
        ],
        // Chi phí nội bộ / giá vốn: Sales mặc định không được xem (mục 6).
        'internal_costs' => [
            'label' => 'Chi phí nội bộ & giá vốn',
            'group' => 'Tài chính',
            'icon' => 'heroicon-o-lock-closed',
            'sort' => 50,
            'phase' => 2,
            'menu' => false,
            'abilities' => ['view'],
        ],

        // --- Hệ thống -----------------------------------------------------
        'users' => [
            'label' => 'Người dùng',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-users',
            'sort' => 10,
            'phase' => 1,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],
        'roles' => [
            'label' => 'Vai trò & quyền',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-shield-check',
            'sort' => 20,
            'phase' => 1,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],
        'schedule_types' => [
            'label' => 'Loại lịch',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-tag',
            'sort' => 25,
            'phase' => 6,
            'menu' => true,
            'abilities' => ['view', 'create', 'update', 'delete'],
        ],
        'notifications' => [
            'label' => 'Thông báo',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-bell',
            'sort' => 30,
            'phase' => 6,
            'menu' => true,
            'abilities' => [],
        ],
        'audit_logs' => [
            'label' => 'Audit Log',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-clipboard-document-list',
            'sort' => 40,
            'phase' => 6,
            'menu' => true,
            'abilities' => ['view'],
        ],
        'settings' => [
            'label' => 'Cấu hình',
            'group' => 'Hệ thống',
            'icon' => 'heroicon-o-cog-6-tooth',
            'sort' => 50,
            'phase' => 6,
            'menu' => true,
            'abilities' => ['view', 'update'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quyền mặc định của các vai trò hệ thống
    |--------------------------------------------------------------------------
    |
    | Áp dụng khi vai trò được TẠO MỚI, và cho những quyền MỚI XUẤT HIỆN ở các
    | phase sau (vai trò hệ thống được cấp thêm quyền mới theo danh sách này).
    | Quyền đã chỉnh trong màn hình "Vai trò & quyền" không bị ghi đè.
    | Hỗ trợ ký tự đại diện: "customers.*", "*.view".
    | Super Admin không cần khai báo: luôn có toàn quyền.
    |
    */
    'role_defaults' => [

        // Xem toàn bộ hệ thống, duyệt các khoản quan trọng, xem tài chính & lợi nhuận.
        // Quản lý danh mục dịch vụ, và cùng Kế toán lập báo giá, hợp đồng, nghiệm thu.
        'Director' => [
            '*.view',
            '*.view_all',
            '*.export',
            '*.approve',
            'services.*',
            'schedules.*',
            'schedule_types.*',
            'tasks.*',
            'quotations.*',
            'contracts.*',
            'acceptances.*',
        ],

        // Khách hàng và cơ hội. Báo giá, hợp đồng và mọi số tiền của hợp đồng
        // chỉ Giám đốc và Kế toán xem được.
        'Sales' => [
            'customers.*',
            'opportunities.*',
            'services.view',
        ],

        // Công việc, lịch làm việc, chấm công, đề nghị chi, mua hàng. Không xem hợp đồng, báo giá.
        'Project Manager' => [
            'schedules.*',
            'schedule_types.*',
            'tasks.*',
            'timesheets.*',
            'expenses.view', 'expenses.view_all', 'expenses.create', 'expenses.update', 'expenses.export',
            'purchases.view', 'purchases.create', 'purchases.update',
            'suppliers.view',
            'customers.view',
            'services.view',
            'internal_costs.view',
        ],

        // Báo giá, hợp đồng, nghiệm thu, hóa đơn, thanh toán, công nợ, chi phí, nhà cung cấp.
        // Duyệt báo giá / hợp đồng vẫn là việc của Giám đốc.
        'Accountant' => [
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.export',
            'contracts.view', 'contracts.create', 'contracts.update',
            'acceptances.view', 'acceptances.create', 'acceptances.update',
            'expenses.view', 'expenses.view_all', 'expenses.update', 'expenses.pay', 'expenses.export',
            'tasks.view', 'tasks.view_all', 'tasks.export',
            'timesheets.view', 'timesheets.view_all',
            'invoices.*',
            'payments.*',
            'receivables.*',
            'suppliers.*',
            'profit_reports.*',
            'customers.view',
            'opportunities.view',
            'services.view',
            'purchases.view',
            'internal_costs.view',
        ],

        // Nhà cung cấp, yêu cầu mua hàng, đơn mua hàng.
        'Procurement' => [
            'suppliers.*',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
            'internal_costs.view',
        ],

        // Xem công việc được giao (không có view_all), cập nhật công việc, chấm công, tạo đề nghị chi.
        'Employee' => [
            'tasks.view', 'tasks.update',
            'timesheets.view', 'timesheets.create', 'timesheets.update', 'timesheets.delete',
            'expenses.view', 'expenses.create',
        ],
    ],
];
