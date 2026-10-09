<?php

namespace App\Filament\Resources\Roles\Concerns;

use App\Enums\SystemRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Support\Access\AccessCatalog;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Arr;

/**
 * Dùng chung cho trang tạo và sửa vai trò: tách các checkbox quyền ra khỏi dữ
 * liệu form (chúng không phải cột của bảng roles) rồi đồng bộ sau khi lưu.
 */
trait SyncsRolePermissions
{
    /**
     * @var list<string>
     */
    protected array $selectedPermissions = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullPermissionsFromFormData(array $data): array
    {
        $selected = Arr::flatten($data[RoleForm::PERMISSIONS_FIELD] ?? []);

        // Chỉ nhận những quyền có trong danh mục.
        $this->selectedPermissions = array_values(array_intersect(
            AccessCatalog::permissionNames(),
            array_filter($selected, 'is_string'),
        ));

        unset($data[RoleForm::PERMISSIONS_FIELD]);

        return $data;
    }

    protected function syncSelectedPermissions(): void
    {
        // Super Admin có toàn quyền qua Gate::before: không lưu quyền riêng.
        if ($this->getRecord()->name === SystemRole::SuperAdmin->value) {
            return;
        }

        $role = $this->getRecord();
        $before = $role->permissions()->pluck('name')->all();

        $role->syncPermissions($this->selectedPermissions);

        // Đổi phân quyền là thao tác nhạy cảm: ghi rõ quyền nào được thêm, quyền nào bị bỏ.
        $added = array_values(array_diff($this->selectedPermissions, $before));
        $removed = array_values(array_diff($before, $this->selectedPermissions));

        if ($added === [] && $removed === []) {
            return;
        }

        $labels = fn (array $permissions): string => implode('; ', array_map(
            fn (string $permission): string => AccessCatalog::permissionLabel($permission),
            $permissions,
        ));

        AuditLogger::record(
            'permissions_updated',
            $role,
            label: 'Vai trò '.$role->name,
            description: 'Thêm '.count($added).' quyền, bỏ '.count($removed).' quyền',
            old: $removed === [] ? [] : ['Quyền bị bỏ' => $labels($removed)],
            new: $added === [] ? [] : ['Quyền được thêm' => $labels($added)],
        );
    }
}
