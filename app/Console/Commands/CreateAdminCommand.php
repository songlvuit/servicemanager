<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Họ tên}
        {--email= : Email đăng nhập}
        {--password= : Mật khẩu (bỏ trống để nhập ẩn)}';

    protected $description = 'Tạo tài khoản Super Admin (dùng khi triển khai production)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: text(label: 'Họ tên', required: true),
            'email' => $this->option('email') ?: text(label: 'Email đăng nhập', required: true),
            'password' => $this->option('password') ?: password(label: 'Mật khẩu (tối thiểu 8 ký tự)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        // Bảo đảm vai trò hệ thống đã tồn tại.
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => true,
        ]);

        $user->assignRole(SystemRole::SuperAdmin->value);

        $this->components->info("Đã tạo Super Admin: {$user->email}");

        return self::SUCCESS;
    }
}
