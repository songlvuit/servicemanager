<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_super_admin_can_open_the_user_list(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);

        $this->actingAs($admin)
            ->get(UserResource::getUrl('index'))
            ->assertOk();
    }

    public function test_employee_cannot_open_the_user_list(): void
    {
        $employee = $this->createUserWithRole(SystemRole::Employee);

        $this->actingAs($employee)
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_director_can_view_users_but_cannot_create_them(): void
    {
        $director = $this->createUserWithRole(SystemRole::Director);

        $this->actingAs($director);

        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
    }

    public function test_super_admin_can_create_a_user_with_a_role(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);
        $salesRole = Role::findByName(SystemRole::Sales->value);

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nguyễn Văn A',
                'email' => 'nguyenvana@example.com',
                'phone' => '0901234567',
                'position' => 'Nhân viên kinh doanh',
                'password' => 'mat-khau-123',
                'password_confirmation' => 'mat-khau-123',
                'roles' => [$salesRole->getKey()],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nguyenvana@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Nguyễn Văn A', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole(SystemRole::Sales->value));
        // Mật khẩu được băm, không lưu dạng thô.
        $this->assertNotSame('mat-khau-123', $user->password);
        $this->assertTrue(Hash::check('mat-khau-123', $user->password));
    }

    public function test_user_form_validates_required_fields_and_unique_email(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);
        $existing = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => '',
                'email' => $existing->email,
                'password' => 'mat-khau-123',
                'password_confirmation' => 'khong-khop',
            ])
            ->call('create')
            ->assertHasFormErrors(['name', 'email', 'password', 'roles']);
    }

    public function test_editing_a_user_without_a_new_password_keeps_the_old_one(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);
        $user = $this->createUserWithRole(SystemRole::Employee);
        $oldHash = $user->password;

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'name' => 'Tên mới',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Tên mới', $user->name);
        $this->assertSame($oldHash, $user->password);
        $this->assertTrue($user->hasRole(SystemRole::Employee->value));
    }

    public function test_policy_protects_super_admins_and_prevents_self_deletion(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);

        // Một vai trò tuỳ chỉnh có đủ quyền quản lý người dùng nhưng không phải Super Admin.
        $managerRole = Role::create(['name' => 'Quản lý nhân sự', 'guard_name' => 'web']);
        $managerRole->givePermissionTo(['users.view', 'users.create', 'users.update', 'users.delete']);

        $manager = User::factory()->create();
        $manager->assignRole($managerRole);

        $employee = $this->createUserWithRole(SystemRole::Employee);

        $this->assertTrue($manager->can('update', $employee));
        $this->assertTrue($manager->can('delete', $employee));

        $this->assertFalse($manager->can('update', $admin));
        $this->assertFalse($manager->can('delete', $admin));
        $this->assertFalse($manager->can('delete', $manager));
    }
}
