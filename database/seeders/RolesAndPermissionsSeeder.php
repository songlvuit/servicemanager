<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Support\Access\AccessCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tạo danh mục quyền và các vai trò hệ thống theo config/access.php.
 *
 * Chạy lại nhiều lần đều an toàn (dùng cho cả production):
 *  - quyền / vai trò còn thiếu sẽ được tạo thêm;
 *  - vai trò VỪA ĐƯỢC TẠO nhận toàn bộ quyền mặc định;
 *  - vai trò đã có chỉ được cấp thêm những quyền VỪA ĐƯỢC TẠO trong lần chạy
 *    này (module mới của phase sau), nên những chỉnh sửa trong màn hình
 *    "Vai trò & quyền" không bị ghi đè.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $guard = AccessCatalog::guard();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $newPermissions = [];

        foreach (AccessCatalog::permissionNames() as $name) {
            if (Permission::findOrCreate($name, $guard)->wasRecentlyCreated) {
                $newPermissions[] = $name;
            }
        }

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::findOrCreate($systemRole->value, $guard);

            // Super Admin có toàn quyền qua Gate::before, không cần gán quyền.
            if ($systemRole === SystemRole::SuperAdmin) {
                continue;
            }

            $defaults = AccessCatalog::defaultPermissionsFor($systemRole->value);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($defaults);

                continue;
            }

            $toGrant = array_values(array_intersect($defaults, $newPermissions));

            if ($toGrant !== []) {
                $role->givePermissionTo($toGrant);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
