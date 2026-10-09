<?php

namespace Tests\Feature;

use App\Enums\OpportunityStatus;
use App\Enums\SystemRole;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAndOpportunityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_services_get_a_code_without_a_year(): void
    {
        $this->assertSame('SV-0001', Service::factory()->create()->code);
        $this->assertSame('SV-0002', Service::factory()->create()->code);
    }

    public function test_service_catalog_is_read_only_for_sales_and_managed_by_the_director(): void
    {
        $service = Service::factory()->create();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        $this->get(ServiceResource::getUrl('index'))->assertOk();
        $this->get(ServiceResource::getUrl('create'))->assertForbidden();
        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertForbidden();

        $this->actingAs($this->createUserWithRole(SystemRole::Director));

        $this->get(ServiceResource::getUrl('create'))->assertOk();
        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))->assertOk();
    }

    public function test_default_cost_is_hidden_from_users_without_internal_cost_permission(): void
    {
        Service::factory()->create();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(ServiceResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('Giá vốn');

        $this->actingAs($this->createUserWithRole(SystemRole::Director))
            ->get(ServiceResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Giá vốn');
    }

    public function test_expected_revenue_is_value_times_probability(): void
    {
        $opportunity = Opportunity::factory()->create([
            'estimated_value' => 250000000,
            'probability' => 70,
        ]);

        $this->assertSame('175000000.00', $opportunity->expected_revenue);
        $this->assertSame('0.00', Opportunity::factory()->create(['probability' => 0])->expected_revenue);
    }

    public function test_opportunity_gets_a_code_and_defaults_to_the_current_user_as_owner(): void
    {
        $this->travelTo('2026-05-10 09:00:00');

        $sales = $this->createUserWithRole(SystemRole::Sales);
        $this->actingAs($sales);

        $opportunity = Opportunity::create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'title' => 'TVC ra mắt sản phẩm',
            'estimated_value' => 100000000,
            'probability' => 50,
        ]);

        $this->assertSame('OPP-2026-0001', $opportunity->code);
        $this->assertSame($sales->getKey(), $opportunity->sales_owner_id);
        $this->assertSame(OpportunityStatus::New, $opportunity->fresh()->status);
    }

    public function test_opportunity_pages_can_be_rendered_by_sales(): void
    {
        $opportunity = Opportunity::factory()->create();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales));

        $this->get(OpportunityResource::getUrl('index'))->assertOk()->assertSee($opportunity->code);
        $this->get(OpportunityResource::getUrl('create'))->assertOk();
        $this->get(OpportunityResource::getUrl('edit', ['record' => $opportunity]))->assertOk();
    }

    public function test_open_scope_excludes_won_and_lost_opportunities(): void
    {
        Opportunity::factory()->create(['status' => OpportunityStatus::Negotiation]);
        Opportunity::factory()->create(['status' => OpportunityStatus::Won]);
        Opportunity::factory()->create(['status' => OpportunityStatus::Lost]);

        $this->assertSame(1, Opportunity::query()->open()->count());
    }
}
