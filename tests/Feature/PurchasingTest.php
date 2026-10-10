<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use Filament\Forms\Components\Repeater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use BuildsJobs;
    use RefreshDatabase;

    private User $procurement;

    private User $pm;

    private User $accountant;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->procurement = $this->createUserWithRole(SystemRole::Procurement);
        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
        $this->accountant = $this->createUserWithRole(SystemRole::Accountant);
        $this->director = $this->createUserWithRole(SystemRole::Director);
    }

    public function test_supplier_gets_a_code_and_is_managed_by_procurement(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Thiết bị ABC']);

        $this->assertSame('SUP-'.now()->year.'-0001', $supplier->code);

        $this->actingAs($this->procurement);

        $this->get(SupplierResource::getUrl('index'))->assertOk()->assertSee('Thiết bị ABC');
        $this->get(SupplierResource::getUrl('create'))->assertOk();
        $this->get(SupplierResource::getUrl('edit', ['record' => $supplier]))->assertOk();

        // PM chỉ xem; nhân viên không vào được.
        $this->assertFalse($this->pm->can('update', $supplier));
        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(SupplierResource::getUrl('index'))
            ->assertForbidden();

        // Xoá mềm: nhà cung cấp gắn với chi phí và đơn mua hàng.
        $supplier->delete();
        $this->assertSoftDeleted($supplier);
    }

    public function test_purchase_request_goes_through_the_approval_rules(): void
    {
        $workflow = app(PurchaseRequestWorkflow::class);
        $request = $this->makeRequest(['amount' => 25000000]);

        $this->assertSame('PR-'.now()->year.'-0001', $request->code);

        $request = $workflow->submit($request, $this->procurement);
        $this->assertSame(PurchaseRequestStatus::Submitted, $request->status);
        $this->assertSame(1, $this->pm->notifications()->count());

        // 25 triệu: PM → Kế toán → Giám đốc. Chưa đủ các cấp thì vẫn "Chờ duyệt".
        $request = $workflow->approve($request, $this->pm);
        $request = $workflow->approve($request, $this->accountant);
        $this->assertSame(PurchaseRequestStatus::Submitted, $request->status);
        $this->assertFalse($this->pm->can('approve', $request));

        $request = $workflow->approve($request, $this->director);

        $this->assertSame(PurchaseRequestStatus::Approved, $request->status);
        $this->assertSame($this->director->getKey(), $request->approved_by);
    }

    public function test_purchase_request_can_be_rejected_revised_and_cancelled(): void
    {
        $workflow = app(PurchaseRequestWorkflow::class);
        $request = $workflow->submit($this->makeRequest(), $this->procurement);

        $request = $workflow->reject($request, $this->pm, 'Đã có thiết bị trong kho.');

        $this->assertSame(PurchaseRequestStatus::Rejected, $request->status);
        $this->assertSame('Đã có thiết bị trong kho.', $request->rejection_reason);

        $request = $workflow->revise($request, $this->procurement);
        $this->assertSame(PurchaseRequestStatus::Draft, $request->status);

        $request = $workflow->submit($request, $this->procurement);
        $request = $workflow->cancel($request, $this->procurement);

        $this->assertSame(PurchaseRequestStatus::Cancelled, $request->status);
        // Lần duyệt đang chờ cũng bị huỷ: không còn nằm trong danh sách chờ của PM.
        $this->assertSame(0, PurchaseRequest::query()->awaitingApprovalBy($this->pm)->count());
    }

    public function test_purchase_order_totals_and_lifecycle_drive_the_request(): void
    {
        $request = $this->approvedRequest();
        $orders = app(PurchaseOrderWorkflow::class);

        $order = PurchaseOrder::factory()->create([
            'task_id' => $request->task_id,
            'purchase_request_id' => $request->getKey(),
        ]);
        $order->items()->create(['description' => 'Micro', 'quantity' => 2, 'unit_price' => 3000000, 'tax_rate' => 10]);
        $order->items()->create(['description' => 'Dây cáp', 'quantity' => 2.5, 'unit_price' => 100000, 'tax_rate' => 8]);

        $order = $orders->recalculate($order);

        $this->assertSame('PO-'.now()->year.'-0001', $order->code);
        // 6.000.000 + 250.000; thuế 600.000 + 20.000.
        $this->assertSame('6250000.00', $order->subtotal);
        $this->assertSame('620000.00', $order->tax);
        $this->assertSame('6870000.00', $order->total);

        $order = $orders->order($order, $this->procurement);
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->status);
        $this->assertSame(PurchaseRequestStatus::Ordered, $request->refresh()->status);

        $order = $orders->receive($order, $this->procurement);
        $this->assertSame(PurchaseRequestStatus::Received, $request->refresh()->status);
        $this->assertNotNull($order->received_at);

        $order = $orders->complete($order, $this->procurement);
        $this->assertSame(PurchaseOrderStatus::Completed, $order->status);
        $this->assertSame(PurchaseRequestStatus::Completed, $request->refresh()->status);
    }

    public function test_cancelling_an_ordered_purchase_order_frees_the_request(): void
    {
        $request = $this->approvedRequest();
        $orders = app(PurchaseOrderWorkflow::class);

        $order = PurchaseOrder::factory()->create(['task_id' => $request->task_id, 'purchase_request_id' => $request->getKey()]);
        $order->items()->create(['description' => 'Micro', 'quantity' => 1, 'unit_price' => 5000000]);

        $order = $orders->order($orders->recalculate($order), $this->procurement);
        $order = $orders->cancel($order, $this->procurement);

        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);
        $this->assertSame(PurchaseRequestStatus::Approved, $request->refresh()->status);

        // Đơn đã nhận hàng thì không huỷ được.
        $second = PurchaseOrder::factory()->create(['task_id' => $request->task_id, 'purchase_request_id' => $request->getKey()]);
        $second->items()->create(['description' => 'Micro', 'quantity' => 1, 'unit_price' => 5000000]);
        $second = $orders->receive($orders->order($orders->recalculate($second), $this->procurement), $this->procurement);

        $this->expectException(WorkflowException::class);

        $orders->cancel($second, $this->procurement);
    }

    public function test_order_needs_items_and_an_approved_request_or_approval_right(): void
    {
        $orders = app(PurchaseOrderWorkflow::class);

        // Đơn chưa có dòng hàng.
        $empty = PurchaseOrder::factory()->create(['purchase_request_id' => $this->approvedRequest()->getKey()]);

        try {
            $orders->order($empty, $this->procurement);
            $this->fail('Đơn chưa có dòng hàng không được đặt.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('chưa có dòng hàng', $exception->getMessage());
        }

        // Đơn lập trực tiếp (không qua yêu cầu): bộ phận mua hàng không tự đặt được, Giám đốc thì được.
        $direct = PurchaseOrder::factory()->create();
        $direct->items()->create(['description' => 'Thuê xe', 'quantity' => 1, 'unit_price' => 2000000]);

        try {
            $orders->order($direct, $this->procurement);
            $this->fail('Đơn lập trực tiếp cần quyền Duyệt để đặt hàng.');
        } catch (AuthorizationException) {
            $this->assertSame(PurchaseOrderStatus::Draft, $direct->refresh()->status);
        }

        $this->assertSame(PurchaseOrderStatus::Ordered, $orders->order($direct, $this->director)->status);
    }

    public function test_purchase_order_is_created_from_an_approved_request_with_the_form(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $supplier = Supplier::factory()->create();
        $request = $this->approvedRequest(['supplier_id' => $supplier->getKey(), 'amount' => 8000000]);

        $this->actingAs($this->procurement);

        // Trang yêu cầu mua hàng có nút "Lập đơn mua hàng".
        Livewire::test(ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('createOrder');

        Livewire::withQueryParams(['request' => $request->getKey()])
            ->test(CreatePurchaseOrder::class)
            ->assertSchemaStateSet([
                'purchase_request_id' => $request->getKey(),
                'task_id' => $request->task_id,
                'supplier_id' => $supplier->getKey(),
            ])
            ->fillForm([
                'items' => [
                    ['description' => 'Máy quay phụ', 'quantity' => 1, 'unit_price' => 7500000, 'tax_rate' => 10],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $order = PurchaseOrder::query()->first();

        $this->assertNotNull($order);
        $this->assertSame($request->getKey(), $order->purchase_request_id);
        $this->assertSame(BudgetCategory::Equipment, $order->category);
        $this->assertSame('7500000.00', $order->subtotal);
        $this->assertSame('8250000.00', $order->total);
        $this->assertSame($this->procurement->getKey(), $order->created_by);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('order')
            ->assertActionHidden('receive')
            ->callAction('order');

        $this->assertSame(PurchaseOrderStatus::Ordered, $order->refresh()->status);
        $this->assertFalse(PurchaseOrderResource::canEdit($order));
    }

    public function test_purchasing_pages_respect_permissions(): void
    {
        $request = $this->makeRequest();
        $order = PurchaseOrder::factory()->create();

        $this->actingAs($this->procurement);

        $this->get(PurchaseRequestResource::getUrl('index'))->assertOk()->assertSee($request->code);
        $this->get(PurchaseRequestResource::getUrl('create'))->assertOk();
        $this->get(PurchaseRequestResource::getUrl('view', ['record' => $request]))->assertOk();
        $this->get(PurchaseRequestResource::getUrl('edit', ['record' => $request]))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('index'))->assertOk()->assertSee($order->code);
        $this->get(PurchaseOrderResource::getUrl('create'))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('edit', ['record' => $order]))->assertOk();

        $this->actingAs($this->createUserWithRole(SystemRole::Employee));

        $this->get(PurchaseRequestResource::getUrl('index'))->assertForbidden();
        $this->get(PurchaseOrderResource::getUrl('index'))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRequest(array $attributes = []): PurchaseRequest
    {
        return PurchaseRequest::factory()->create([
            'task_id' => $this->makeJob()->getKey(),
            'requester_id' => $this->procurement->getKey(),
            ...$attributes,
        ])->refresh();
    }

    /**
     * Yêu cầu mua hàng 10 triệu (mặc định) đã qua PM và kế toán.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function approvedRequest(array $attributes = []): PurchaseRequest
    {
        $workflow = app(PurchaseRequestWorkflow::class);
        $request = $workflow->submit($this->makeRequest($attributes), $this->procurement);
        $request = $workflow->approve($request, $this->pm);

        return $workflow->approve($request, $this->accountant);
    }
}
