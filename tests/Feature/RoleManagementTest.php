<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->admin = $this->createUserWithRole(SystemRole::SuperAdmin);
    }

    public function test_super_admin_can_open_role_pages(): void
    {
        $this->actingAs($this->admin);

        $this->get(RoleResource::getUrl('index'))->assertOk();
        $this->get(RoleResource::getUrl('create'))->assertOk();
        $this->get(RoleResource::getUrl('edit', ['record' => Role::findByName(SystemRole::Sales->value)]))->assertOk();
    }

    public function test_employee_cannot_open_role_pages(): void
    {
        $employee = $this->createUserWithRole(SystemRole::Employee);

        $this->actingAs($employee)
            ->get(RoleResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_creating_a_role_saves_the_selected_permissions(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Thực tập sinh',
                'module_permissions.customers' => ['customers.view'],
                'module_permissions.tasks' => ['tasks.view', 'tasks.update'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::findByName('Thực tập sinh');

        $this->assertEqualsCanonicalizing(
            ['customers.view', 'tasks.view', 'tasks.update'],
            $role->permissions->pluck('name')->all(),
        );
    }

    public function test_editing_a_role_syncs_its_permissions(): void
    {
        $role = Role::create(['name' => 'Cộng tác viên', 'guard_name' => 'web']);
        $role->givePermissionTo(['quotations.view', 'quotations.create', 'tasks.view']);

        $this->actingAs($this->admin);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                // Bỏ toàn bộ quyền báo giá, thêm quyền khách hàng, giữ nguyên công việc.
                'module_permissions.quotations' => [],
                'module_permissions.customers' => ['customers.view', 'customers.update'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['tasks.view', 'customers.view', 'customers.update'],
            $role->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_saving_a_role_without_changes_keeps_its_permissions(): void
    {
        $role = Role::findByName(SystemRole::Sales->value);
        $before = $role->permissions->pluck('name')->all();

        $this->actingAs($this->admin);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing($before, $role->fresh()->permissions->pluck('name')->all());
        // Vai trò hệ thống không bị đổi tên.
        $this->assertSame(SystemRole::Sales->value, $role->fresh()->name);
    }

    public function test_system_roles_cannot_be_deleted_by_role_managers(): void
    {
        $managerRole = Role::create(['name' => 'Quản lý phân quyền', 'guard_name' => 'web']);
        $managerRole->givePermissionTo(['roles.view', 'roles.create', 'roles.update', 'roles.delete']);

        $manager = User::factory()->create();
        $manager->assignRole($managerRole);

        $customRole = Role::create(['name' => 'Vai trò tạm', 'guard_name' => 'web']);

        $this->assertTrue($manager->can('delete', $customRole));
        $this->assertFalse($manager->can('delete', Role::findByName(SystemRole::Sales->value)));
        $this->assertFalse($manager->can('update', Role::findByName(SystemRole::SuperAdmin->value)));
    }
}
