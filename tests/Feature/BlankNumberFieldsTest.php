<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\ApprovalRules\Pages\ManageApprovalRules;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ApprovalRule;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Ô số không bắt buộc bị XOÁ TRẮNG trên form phải lưu thành 0, không được
 * gửi giá trị rỗng vào cột NOT NULL (lỗi "null value in column ... violates
 * not-null constraint" khi tạo dịch vụ mà bỏ trống giá vốn).
 */
class BlankNumberFieldsTest extends TestCase
{
    use BuildsJobs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->admin = $this->createUserWithRole(SystemRole::SuperAdmin);
        $this->actingAs($this->admin);
    }

    public function test_service_can_be_saved_with_a_blank_cost(): void
    {
        Livewire::test(CreateService::class)
            ->fillForm([
                'name' => 'Quay phim',
                'default_unit' => 'Buổi',
                'default_price' => 1500000,
                'default_cost' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::query()->sole();

        $this->assertSame('SV-0001', $service->code);
        $this->assertSame('1500000.00', $service->default_price);
        $this->assertSame('0.00', $service->default_cost);

        // Sửa và xoá trắng lại giá vốn cũng không lỗi.
        $service->update(['default_cost' => 900000]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['default_cost' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.00', $service->refresh()->default_cost);
    }

    public function test_user_can_be_saved_with_a_blank_session_rate(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nguyễn Văn A',
                'email' => 'a@example.com',
                'password' => 'mat-khau-123',
                'password_confirmation' => 'mat-khau-123',
                'roles' => [Role::findByName(SystemRole::Employee->value)->getKey()],
                'session_rate' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'a@example.com')->sole();

        $this->assertSame('0.00', $user->session_rate);

        $user->update(['session_rate' => 150000]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['session_rate' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.00', $user->refresh()->session_rate);
    }

    public function test_task_can_be_saved_with_blank_hours_and_progress(): void
    {
        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'name' => 'Viết kịch bản',
                'estimated_sessions' => null,
                'progress' => null,
            ])
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();

        $this->assertSame('0.00', $task->estimated_sessions);
        $this->assertSame(0, $task->progress);
    }

    public function test_quotation_item_can_be_saved_with_blank_discount_tax_and_cost(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateQuotation::class)
            ->fillForm([
                'customer_id' => Customer::factory()->create()->getKey(),
                'title' => 'Báo giá thử',
                'quotation_date' => today()->toDateString(),
                'valid_until' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'description' => 'Quay phim',
                        'quantity' => 2,
                        'unit_price' => 5000000,
                        'discount' => null,
                        'tax_rate' => null,
                        'estimated_cost' => null,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $item = Quotation::query()->sole()->items()->sole();

        $this->assertSame('0.00', $item->discount);
        $this->assertSame('0.00', $item->tax_rate);
        $this->assertSame('0.00', $item->estimated_cost);
        $this->assertSame('10000000.00', $item->subtotal);
    }

    public function test_purchase_order_item_can_be_saved_with_a_blank_tax_rate(): void
    {
        $undoRepeaterFake = Repeater::fake();
        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'task_id' => $this->makeJob()->getKey(),
                'supplier_id' => Supplier::factory()->create()->getKey(),
                'items' => [
                    ['description' => 'Thuê máy quay', 'quantity' => 1, 'unit_price' => 3000000, 'tax_rate' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $item = PurchaseOrder::query()->sole()->items()->sole();

        $this->assertSame('0.00', $item->tax_rate);
        $this->assertSame('3000000.00', $item->subtotal);
    }

    public function test_blank_upper_limit_of_an_approval_step_still_means_unlimited(): void
    {
        Livewire::test(ManageApprovalRules::class)
            ->callAction('create', data: [
                'name' => 'Giám đốc duyệt khoản lớn',
                'document_type' => 'expense',
                'required_role' => SystemRole::Director->value,
                'min_amount' => 50000000,
                'max_amount' => null,
                'sequence' => 40,
            ])
            ->assertHasNoFormErrors();

        $rule = ApprovalRule::query()->where('name', 'Giám đốc duyệt khoản lớn')->sole();

        $this->assertSame('50000000.00', $rule->min_amount);
        $this->assertNull($rule->max_amount);
    }
}
