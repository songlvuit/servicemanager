<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\SystemRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_customer_gets_a_sequential_code_and_its_creator(): void
    {
        $this->travelTo('2026-05-10 09:00:00');

        $sales = $this->createUserWithRole(SystemRole::Sales);
        $this->actingAs($sales);

        $first = Customer::create(['name' => 'Công ty A']);
        $second = Customer::create(['name' => 'Công ty B']);

        $this->assertSame('CUS-2026-0001', $first->code);
        $this->assertSame('CUS-2026-0002', $second->code);
        $this->assertSame($sales->getKey(), $first->created_by);
        $this->assertSame(CustomerStatus::Lead, $first->fresh()->status);
    }

    public function test_deleting_a_customer_is_a_soft_delete_and_its_code_is_not_reused(): void
    {
        $this->travelTo('2026-05-10 09:00:00');

        $customer = Customer::factory()->create();
        $customer->delete();

        $this->assertSoftDeleted($customer);
        $this->assertSame('CUS-2026-0002', Customer::factory()->create()->code);
    }

    public function test_sales_can_create_a_customer_from_the_form(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);
        $this->actingAs($sales);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Công ty TNHH Thử Nghiệm',
                'tax_code' => '0312345678',
                'email' => 'lienhe@thunghiem.example.com',
                'phone' => '0901234567',
                'website' => 'https://thunghiem.example.com',
                'province' => 'Hà Nội',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('tax_code', '0312345678')->first();

        $this->assertNotNull($customer);
        $this->assertStringStartsWith('CUS-', $customer->code);
        $this->assertSame($sales->getKey(), $customer->created_by);
    }

    public function test_customer_form_validates_input(): void
    {
        Customer::factory()->create(['tax_code' => '0399999999']);

        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => '',
                'tax_code' => '0399999999',
                'email' => 'khong-phai-email',
                'website' => 'javascript:alert(1)',
            ])
            ->call('create')
            ->assertHasFormErrors(['name', 'tax_code', 'email', 'website']);
    }

    public function test_customer_pages_follow_role_permissions(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        $this->get(CustomerResource::getUrl('index'))->assertOk();
        $this->get(CustomerResource::getUrl('create'))->assertOk();
        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk()->assertSee($customer->code);
        $this->get(CustomerResource::getUrl('edit', ['record' => $customer]))->assertOk();

        // Nhân viên không có quyền trên module khách hàng.
        $this->actingAs($this->createUserWithRole(SystemRole::Employee));

        $this->get(CustomerResource::getUrl('index'))->assertForbidden();

        // Quản lý dự án chỉ được xem.
        $this->actingAs($this->createUserWithRole(SystemRole::ProjectManager));

        $this->get(CustomerResource::getUrl('index'))->assertOk();
        $this->get(CustomerResource::getUrl('create'))->assertForbidden();
        $this->get(CustomerResource::getUrl('edit', ['record' => $customer]))->assertForbidden();
    }

    public function test_each_customer_has_exactly_one_primary_contact(): void
    {
        $customer = Customer::factory()->create();

        $first = $customer->contacts()->create(['name' => 'Người thứ nhất']);
        $second = $customer->contacts()->create(['name' => 'Người thứ hai']);

        // Liên hệ đầu tiên tự động là liên hệ chính.
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_primary);

        $second->update(['is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame($second->getKey(), $customer->primaryContact->getKey());
    }
}
