<?php

namespace Tests\Feature;

use App\Enums\QuotationStatus;
use App\Enums\SystemRole;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

class QuotationPagesTest extends TestCase
{
    use BuildsQuotations;
    use RefreshDatabase;

    private User $sales;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->sales = $this->createUserWithRole(SystemRole::Sales);
        $this->director = $this->createUserWithRole(SystemRole::Director);
    }

    public function test_sales_can_open_quotation_pages(): void
    {
        $quotation = $this->makeQuotation(creator: $this->sales);

        $this->actingAs($this->sales);

        $this->get(QuotationResource::getUrl('index'))->assertOk()->assertSee($quotation->code);
        $this->get(QuotationResource::getUrl('create'))->assertOk();
        $this->get(QuotationResource::getUrl('view', ['record' => $quotation]))->assertOk()->assertSee('11.000.000');
        $this->get(QuotationResource::getUrl('edit', ['record' => $quotation]))->assertOk();
    }

    public function test_employee_cannot_open_quotations(): void
    {
        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(QuotationResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_submitted_quotation_cannot_be_edited_even_by_super_admin(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);

        $this->actingAs($this->sales)
            ->get(QuotationResource::getUrl('edit', ['record' => $quotation]))
            ->assertForbidden();

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin))
            ->get(QuotationResource::getUrl('edit', ['record' => $quotation]))
            ->assertForbidden();
    }

    public function test_edit_and_delete_respect_status_even_for_super_admin(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->assertFalse(QuotationResource::canEdit($quotation));
        $this->assertFalse(QuotationResource::canDelete($quotation));

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionHidden('edit');
    }

    public function test_costs_and_margin_are_hidden_from_sales_but_visible_to_the_director(): void
    {
        $quotation = $this->makeQuotation(creator: $this->sales);

        $this->actingAs($this->sales)
            ->get(QuotationResource::getUrl('view', ['record' => $quotation]))
            ->assertOk()
            ->assertDontSee('Giá vốn')
            ->assertDontSee('Lợi nhuận ước tính');

        $this->actingAs($this->director)
            ->get(QuotationResource::getUrl('view', ['record' => $quotation]))
            ->assertOk()
            ->assertSee('Giá vốn ước tính')
            ->assertSee('Lợi nhuận ước tính');
    }

    public function test_low_margin_warning_is_shown_on_the_quotation(): void
    {
        $healthy = $this->makeQuotation(creator: $this->sales);
        $lowMargin = $this->makeQuotation([['unit_price' => 10000000, 'estimated_cost' => 9000000]], $this->sales);

        $this->actingAs($this->director);

        $this->get(QuotationResource::getUrl('view', ['record' => $healthy]))
            ->assertOk()
            ->assertDontSee('Biên lợi nhuận thấp.');

        $this->get(QuotationResource::getUrl('view', ['record' => $lowMargin]))
            ->assertOk()
            ->assertSee('Biên lợi nhuận thấp.');
    }

    public function test_sales_can_create_a_quotation_with_items_from_the_form(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $customer = Customer::factory()->create();

        $this->actingAs($this->sales);

        Livewire::test(CreateQuotation::class)
            ->fillForm([
                'customer_id' => $customer->getKey(),
                'title' => 'TVC ra mắt sản phẩm',
                'quotation_date' => today()->toDateString(),
                'valid_until' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'description' => 'Quay phim',
                        'quantity' => 2,
                        'unit' => 'ngày',
                        'unit_price' => 15000000,
                        'discount' => 0,
                        'tax_rate' => 10,
                    ],
                    [
                        'description' => 'Dựng phim',
                        'quantity' => 1,
                        'unit' => 'sản phẩm',
                        'unit_price' => 12000000,
                        'discount' => 2000000,
                        'tax_rate' => 10,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $quotation = Quotation::query()->where('title', 'TVC ra mắt sản phẩm')->first();

        $this->assertNotNull($quotation);
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertSame($this->sales->getKey(), $quotation->created_by);
        $this->assertSame(2, $quotation->items()->count());
        // 30.000.000 + (12.000.000 − 2.000.000) = 40.000.000; VAT 10% = 4.000.000
        $this->assertSame('40000000.00', $quotation->subtotal);
        $this->assertSame('2000000.00', $quotation->discount);
        $this->assertSame('44000000.00', $quotation->total);
    }

    public function test_quotation_form_requires_a_customer_title_and_valid_dates(): void
    {
        $this->actingAs($this->sales);

        Livewire::test(CreateQuotation::class)
            ->fillForm([
                'customer_id' => null,
                'title' => '',
                'quotation_date' => today()->toDateString(),
                'valid_until' => today()->subDay()->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['customer_id', 'title', 'valid_until']);
    }

    public function test_workflow_buttons_follow_status_and_permissions(): void
    {
        $quotation = $this->makeQuotation(creator: $this->sales);

        // Bản nháp: Sales thấy "Gửi duyệt", không thấy "Duyệt".
        $this->actingAs($this->sales);

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionVisible('submit')
            ->assertActionHidden('approve')
            ->assertActionHidden('markSent')
            ->callAction('submit');

        $this->assertSame(QuotationStatus::PendingApproval, $quotation->fresh()->status);

        // Chờ duyệt: Sales vẫn không duyệt được.
        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionHidden('submit')
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');

        // Giám đốc duyệt, kèm ghi chú.
        $this->actingAs($this->director);

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->callAction('approve', data: ['note' => 'Đồng ý với mức giá này.']);

        $quotation->refresh();

        $this->assertSame(QuotationStatus::Approved, $quotation->status);
        $this->assertSame($this->director->getKey(), $quotation->approved_by);
        $this->assertSame('Đồng ý với mức giá này.', $quotation->approval_note);
    }

    public function test_rejecting_from_the_page_requires_a_reason(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);

        $this->actingAs($this->director);

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasFormErrors(['reason' => ['required']]);

        $this->assertSame(QuotationStatus::PendingApproval, $quotation->fresh()->status);

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getRouteKey()])
            ->callAction('reject', data: ['reason' => 'Chiết khấu quá cao.'])
            ->assertHasNoFormErrors();

        $this->assertSame(QuotationStatus::Rejected, $quotation->fresh()->status);
        $this->assertSame('Chiết khấu quá cao.', $quotation->fresh()->rejection_reason);
    }

    public function test_pending_quotations_are_counted_on_the_menu_for_approvers_only(): void
    {
        $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);
        $this->makeQuotation(creator: $this->sales);

        $this->actingAs($this->sales);
        $this->assertNull(QuotationResource::getNavigationBadge());

        $this->actingAs($this->director);
        $this->assertSame('1', QuotationResource::getNavigationBadge());
    }
}
