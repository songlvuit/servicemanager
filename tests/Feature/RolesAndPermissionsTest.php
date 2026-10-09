<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use App\Support\Access\AccessCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_seeder_creates_all_system_roles_and_permissions(): void
    {
        foreach (SystemRole::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value, 'guard_name' => 'web']);
        }

        $this->assertSame(count(AccessCatalog::permissionNames()), Permission::count());
        $this->assertDatabaseHas('permissions', ['name' => 'quotations.approve']);
    }

    public function test_seeder_is_idempotent_and_keeps_customised_roles(): void
    {
        $sales = Role::findByName(SystemRole::Sales->value);
        $sales->revokePermissionTo('customers.delete');

        $rolesBefore = Role::count();
        $permissionsBefore = Permission::count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($rolesBefore, Role::count());
        $this->assertSame($permissionsBefore, Permission::count());
        $this->assertFalse($sales->fresh()->hasPermissionTo('customers.delete'));
    }

    public function test_super_admin_can_do_everything_without_explicit_permissions(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);

        $this->assertTrue($admin->can('users.delete'));
        $this->assertTrue($admin->can('quotations.approve'));
        $this->assertTrue($admin->can('settings.update'));
    }

    public function test_director_can_view_and_approve_but_not_manage_users(): void
    {
        $director = $this->createUserWithRole(SystemRole::Director);

        $this->assertTrue($director->can('profit_reports.view'));
        $this->assertTrue($director->can('internal_costs.view'));
        $this->assertTrue($director->can('quotations.approve'));
        $this->assertTrue($director->can('users.view'));
        $this->assertFalse($director->can('users.create'));
        $this->assertFalse($director->can('customers.delete'));
    }

    public function test_sales_manages_customers_but_cannot_see_internal_costs(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);

        $this->assertTrue($sales->can('customers.create'));
        $this->assertTrue($sales->can('quotations.update'));
        $this->assertFalse($sales->can('quotations.approve'));
        $this->assertFalse($sales->can('internal_costs.view'));
        $this->assertFalse($sales->can('expenses.view'));
        $this->assertFalse($sales->can('users.view'));
    }

    public function test_accountant_handles_money_but_not_sales_data(): void
    {
        $accountant = $this->createUserWithRole(SystemRole::Accountant);

        $this->assertTrue($accountant->can('invoices.create'));
        $this->assertTrue($accountant->can('payments.create'));
        $this->assertTrue($accountant->can('expenses.pay'));
        $this->assertFalse($accountant->can('customers.create'));
        $this->assertFalse($accountant->can('quotations.view'));
    }

    public function test_employee_has_only_basic_project_permissions(): void
    {
        $employee = $this->createUserWithRole(SystemRole::Employee);

        $this->assertTrue($employee->can('tasks.update'));
        $this->assertTrue($employee->can('expenses.create'));
        $this->assertFalse($employee->can('quotations.approve'));
        $this->assertFalse($employee->can('invoices.view'));
        $this->assertFalse($employee->can('projects.create'));
    }

    public function test_wildcard_patterns_expand_to_catalog_permissions(): void
    {
        $this->assertSame(
            ['receivables.view', 'receivables.export'],
            AccessCatalog::expand(['receivables.*']),
        );

        $this->assertContains('customers.view', AccessCatalog::expand(['*.view']));
        $this->assertNotContains('customers.create', AccessCatalog::expand(['*.view']));
        $this->assertSame([], AccessCatalog::expand([]));
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('customers.view'));
        $this->assertFalse($user->can('users.view'));
    }
}
