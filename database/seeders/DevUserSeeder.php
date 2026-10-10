<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Tài khoản mẫu cho môi trường phát triển — mật khẩu chung: "password".
 *
 * KHÔNG dùng trên production: ở đó hãy tạo quản trị viên bằng
 * `php artisan app:create-admin`.
 */
class DevUserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * @var list<array{email: string, name: string, position: string, role: SystemRole}>
     */
    private const ACCOUNTS = [
        ['email' => 'admin@example.com', 'name' => 'Quản trị viên', 'position' => 'Quản trị hệ thống', 'role' => SystemRole::SuperAdmin],
        ['email' => 'director@example.com', 'name' => 'Nguyễn Văn Giám', 'position' => 'Giám đốc', 'role' => SystemRole::Director],
        ['email' => 'sales@example.com', 'name' => 'Trần Thị Kinh', 'position' => 'Nhân viên kinh doanh', 'role' => SystemRole::Sales],
        ['email' => 'pm@example.com', 'name' => 'Lê Văn Dự', 'position' => 'Quản lý công việc', 'role' => SystemRole::ProjectManager],
        ['email' => 'accounting@example.com', 'name' => 'Phạm Thị Toán', 'position' => 'Kế toán', 'role' => SystemRole::Accountant],
        ['email' => 'procurement@example.com', 'name' => 'Hoàng Văn Mua', 'position' => 'Nhân viên mua hàng', 'role' => SystemRole::Procurement],
        ['email' => 'employee@example.com', 'name' => 'Đỗ Thị Viên', 'position' => 'Nhân viên', 'role' => SystemRole::Employee],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DevUserSeeder: không tạo tài khoản mẫu trên production.');

            return;
        }

        foreach (self::ACCOUNTS as $account) {
            // Chỉ đặt mật khẩu khi TẠO tài khoản: ghi lại mật khẩu của tài khoản
            // đang có sẽ làm mọi phiên đăng nhập của tài khoản đó bị thoát.
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'position' => $account['position'],
                    'password' => self::PASSWORD,
                    'is_active' => true,
                ],
            );

            if ($user->wasRecentlyCreated) {
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->syncRoles([$account['role']->value]);
            }
        }
    }
}
