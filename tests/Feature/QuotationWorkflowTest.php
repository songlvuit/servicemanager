<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\OpportunityStatus;
use App\Enums\QuotationStatus;
use App\Enums\SystemRole;
use App\Exceptions\QuotationWorkflowException;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

class QuotationWorkflowTest extends TestCase
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

    public function test_happy_path_from_draft_to_accepted(): void
    {
        $customer = Customer::factory()->lead()->create();
        $opportunity = Opportunity::factory()->create(['customer_id' => $customer->getKey()]);

        $quotation = $this->makeQuotation(creator: $this->sales, attributes: [
            'customer_id' => $customer->getKey(),
            'opportunity_id' => $opportunity->getKey(),
        ]);

        $quotation = $this->workflow()->submit($quotation, $this->sales);

        $this->assertSame(QuotationStatus::PendingApproval, $quotation->status);
        $this->assertNotNull($quotation->submitted_at);
        $this->assertFalse($quotation->requires_director_approval);
        // Cơ hội chuyển sang "Đã gửi đề xuất" khi báo giá được gửi duyệt.
        $this->assertSame(OpportunityStatus::Proposal, $opportunity->fresh()->status);

        $quotation = $this->workflow()->approve($quotation, $this->director, 'Đồng ý.');

        $this->assertSame(QuotationStatus::Approved, $quotation->status);
        $this->assertSame($this->director->getKey(), $quotation->approved_by);
        $this->assertNotNull($quotation->approved_at);
        $this->assertSame('Đồng ý.', $quotation->approval_note);

        $quotation = $this->workflow()->markSent($quotation, $this->sales);

        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);

        $quotation = $this->workflow()->markAccepted($quotation, $this->sales);

        $this->assertSame(QuotationStatus::Accepted, $quotation->status);
        $this->assertNotNull($quotation->accepted_at);
        // Thắng cơ hội, khách hàng tiềm năng thành khách hàng đang hoạt động.
        $this->assertSame(OpportunityStatus::Won, $opportunity->fresh()->status);
        $this->assertSame(CustomerStatus::Active, $customer->fresh()->status);
    }

    public function test_quotation_without_items_cannot_be_submitted(): void
    {
        $quotation = $this->makeQuotation([], $this->sales);

        $this->expectException(QuotationWorkflowException::class);

        $this->workflow()->submit($quotation, $this->sales);
    }

    public function test_sales_cannot_approve(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);

        $this->expectException(AuthorizationException::class);

        $this->workflow()->approve($quotation, $this->sales);
    }

    public function test_users_without_permission_cannot_submit(): void
    {
        $employee = $this->createUserWithRole(SystemRole::Employee);
        $quotation = $this->makeQuotation(creator: $this->sales);

        $this->expectException(AuthorizationException::class);

        $this->workflow()->submit($quotation, $employee);
    }

    public function test_discount_above_threshold_requires_the_director(): void
    {
        // Một vai trò tuỳ chỉnh có quyền duyệt nhưng không phải Giám đốc.
        $teamLeadRole = Role::create(['name' => 'Trưởng nhóm kinh doanh', 'guard_name' => 'web']);
        $teamLeadRole->givePermissionTo(['quotations.view', 'quotations.approve']);

        $teamLead = User::factory()->create();
        $teamLead->assignRole($teamLeadRole);

        $normal = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);
        $discounted = $this->workflow()->submit(
            $this->makeQuotation([['unit_price' => 10000000, 'discount' => 2500000]], $this->sales),
            $this->sales,
        );

        $this->assertFalse($normal->requires_director_approval);
        $this->assertTrue($discounted->requires_director_approval);

        // Báo giá thường: trưởng nhóm duyệt được.
        $this->assertTrue($teamLead->can('approve', $normal));
        $this->assertSame(QuotationStatus::Approved, $this->workflow()->approve($normal, $teamLead)->status);

        // Chiết khấu 25%: trưởng nhóm không duyệt được, Giám đốc duyệt được.
        $this->assertFalse($teamLead->can('approve', $discounted));
        $this->assertTrue($this->director->can('approve', $discounted));

        try {
            $this->workflow()->approve($discounted, $teamLead);
            $this->fail('Trưởng nhóm không được phép duyệt báo giá chiết khấu vượt ngưỡng.');
        } catch (AuthorizationException) {
            $this->assertSame(QuotationStatus::PendingApproval, $discounted->fresh()->status);
        }

        $this->assertSame(QuotationStatus::Approved, $this->workflow()->approve($discounted, $this->director)->status);
    }

    public function test_rejection_requires_a_reason_and_allows_revision(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);

        try {
            $this->workflow()->reject($quotation, $this->director, '   ');
            $this->fail('Từ chối phải có lý do.');
        } catch (QuotationWorkflowException) {
            $this->assertSame(QuotationStatus::PendingApproval, $quotation->fresh()->status);
        }

        $quotation = $this->workflow()->reject($quotation, $this->director, 'Đơn giá chưa hợp lý.');

        $this->assertSame(QuotationStatus::Rejected, $quotation->status);
        $this->assertSame('Đơn giá chưa hợp lý.', $quotation->rejection_reason);
        $this->assertFalse($quotation->isEditable());

        $quotation = $this->workflow()->revise($quotation, $this->sales);

        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertTrue($quotation->isEditable());
        $this->assertNull($quotation->submitted_at);
    }

    public function test_items_are_locked_once_the_quotation_is_submitted(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);
        $item = $quotation->items()->first();

        foreach ([
            fn () => $item->update(['unit_price' => 1]),
            fn () => $item->delete(),
            fn () => $quotation->items()->create(['description' => 'Thêm sau', 'quantity' => 1, 'unit_price' => 1000]),
            fn () => $this->workflow()->recalculate($quotation),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Báo giá đã gửi duyệt không được phép thay đổi hạng mục.');
            } catch (QuotationWorkflowException) {
                // Đúng như mong đợi.
            }
        }

        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame('10000000.00', $item->fresh()->unit_price);
        $this->assertSame('11000000.00', $quotation->fresh()->total);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $draft = $this->makeQuotation(creator: $this->sales);

        // Nháp không gửi khách được khi chưa duyệt.
        try {
            $this->workflow()->markSent($draft, $this->sales);
            $this->fail('Báo giá nháp không được chuyển thẳng sang Đã gửi khách.');
        } catch (QuotationWorkflowException) {
            $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        }

        // Đã duyệt thì không duyệt / từ chối lại được.
        $approved = $this->workflow()->approve($this->workflow()->submit($draft, $this->sales), $this->director);

        $this->assertFalse($this->director->can('approve', $approved));

        // Khách đã chấp nhận thì không huỷ được.
        $accepted = $this->workflow()->markAccepted($this->workflow()->markSent($approved, $this->sales), $this->sales);

        $this->expectException(QuotationWorkflowException::class);

        $this->workflow()->cancel($accepted, $this->sales);
    }

    public function test_customer_can_decline_a_sent_quotation(): void
    {
        $quotation = $this->workflow()->submit($this->makeQuotation(creator: $this->sales), $this->sales);
        $quotation = $this->workflow()->approve($quotation, $this->director);
        $quotation = $this->workflow()->markSent($quotation, $this->sales);
        $quotation = $this->workflow()->markDeclined($quotation, $this->sales, 'Vượt ngân sách.');

        $this->assertSame(QuotationStatus::Rejected, $quotation->status);
        $this->assertSame('Khách hàng từ chối: Vượt ngân sách.', $quotation->rejection_reason);
    }

    public function test_draft_can_be_cancelled_with_a_reason(): void
    {
        $quotation = $this->workflow()->cancel($this->makeQuotation(creator: $this->sales), $this->sales, 'Khách đổi kế hoạch.');

        $this->assertSame(QuotationStatus::Cancelled, $quotation->status);
        $this->assertSame('Huỷ: Khách đổi kế hoạch.', $quotation->rejection_reason);
        $this->assertSame([], $quotation->status->allowedTransitions());
    }

    public function test_overdue_quotations_expire(): void
    {
        $overdue = $this->makeQuotation(creator: $this->sales, attributes: [
            'quotation_date' => today()->subDays(40),
            'valid_until' => today()->subDay(),
        ]);
        $stillValid = $this->makeQuotation(creator: $this->sales, attributes: [
            'valid_until' => today(),
        ]);
        $overdueDraft = $this->makeQuotation(creator: $this->sales, attributes: [
            'valid_until' => today()->subDay(),
        ]);

        foreach ([$overdue, $stillValid] as $quotation) {
            $this->workflow()->approve($this->workflow()->submit($quotation, $this->sales), $this->director);
        }

        $this->assertSame(1, $this->workflow()->expireOverdue());

        $this->assertSame(QuotationStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(QuotationStatus::Approved, $stillValid->fresh()->status);
        // Bản nháp không tự hết hạn.
        $this->assertSame(QuotationStatus::Draft, $overdueDraft->fresh()->status);

        $this->artisan('quotations:expire')->assertSuccessful();
    }

    public function test_approvers_and_creator_are_notified(): void
    {
        $quotation = $this->makeQuotation(creator: $this->sales);

        $this->workflow()->submit($quotation, $this->sales);

        // Giám đốc nhận thông báo "cần duyệt"; người gửi không tự nhận.
        $this->assertSame(1, $this->director->notifications()->count());
        $this->assertSame(0, $this->sales->notifications()->count());
        $this->assertSame('Báo giá cần duyệt', $this->director->notifications()->first()->data['title']);

        $this->workflow()->approve($quotation, $this->director);

        $this->assertSame(1, $this->sales->notifications()->count());
        $this->assertSame('Báo giá đã được duyệt', $this->sales->notifications()->first()->data['title']);
    }
}
