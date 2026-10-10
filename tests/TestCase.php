<?php

namespace Tests;

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chốt an toàn: test dùng RefreshDatabase sẽ XOÁ SẠCH database đang kết
     * nối. Nếu cấu hình đã bị cache (`php artisan optimize` / `config:cache`),
     * các giá trị trong phpunit.xml không còn hiệu lực và test sẽ chạy thẳng
     * vào database thật — nên dừng lại ngay trước khi đụng tới dữ liệu.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $isTestDatabase = $connection === 'sqlite' || str_contains($database, 'test');

        if (! $this->app->environment('testing') || ! $isTestDatabase) {
            throw new RuntimeException(
                'Từ chối chạy test: ứng dụng không ở môi trường "testing" hoặc đang trỏ vào database thật '
                ."(env: {$this->app->environment()}, connection: {$connection}). Cấu hình đang bị cache? "
                .'Chạy `php artisan optimize:clear` rồi thử lại, và không chạy test trên production.'
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Tạo danh mục quyền và các vai trò hệ thống (cần RefreshDatabase).
     */
    protected function seedAccessControl(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function createUserWithRole(SystemRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * Người dùng giữ một vai trò tự tạo chỉ có đúng các quyền được nêu.
     *
     * @param  list<string>  $permissions
     */
    protected function createUserWithPermissions(array $permissions, array $attributes = []): User
    {
        $role = Role::create(['name' => 'Vai trò thử '.(Role::query()->count() + 1), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
