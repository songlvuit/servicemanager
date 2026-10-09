<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

/**
 * Nhật ký thao tác (mục 39).
 */
class AuditLogTest extends TestCase
{
    use BuildsExpenses;
    use BuildsInvoices;
    use BuildsProjects;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->admin = $this->createUserWithRole(SystemRole::SuperAdmin, ['name' => 'Quản Trị']);
    }

    public function test_creating_and_updating_a_record_is_logged_with_old_and_new_values(): void
    {
        $this->actingAs($this->admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $customer = Customer::factory()->create(['name' => 'Công ty ABC', 'phone' => '0901000000', 'address' => null]);

        $created = AuditLog::query()->where('action', 'created')->where('auditable_type', $customer->getMorphClass())->sole();

        $this->assertSame($this->admin->getKey(), $created->user_id);
        $this->assertSame('Quản Trị', $created->user_name);
        $this->assertSame('Khách hàng '.$customer->code, $created->label);
        $this->assertSame('Công ty ABC', $created->new_values['name']);
        $this->assertNull($created->old_values);

        $customer->update(['phone' => '0902000000', 'address' => '1 Lê Lợi']);

        $updated = AuditLog::query()->where('action', 'updated')->sole();

        $this->assertSame('Cập nhật: Địa chỉ, Điện thoại', $updated->description);
        $this->assertEquals(['phone' => '0901000000', 'address' => null], $updated->old_values);
        $this->assertEquals(['phone' => '0902000000', 'address' => '1 Lê Lợi'], $updated->new_values);
        $this->assertSame(
            [
                ['field' => 'Địa chỉ', 'old' => '—', 'new' => '1 Lê Lợi'],
                ['field' => 'Điện thoại', 'old' => '0901000000', 'new' => '0902000000'],
            ],
            $updated->changes(),
        );

        // Lưu mà không đổi gì thì không ghi thêm dòng nào.
        $customer->update(['phone' => '0902000000']);

        $this->assertSame(1, AuditLog::query()->where('action', 'updated')->count());

        $customer->delete();
        $customer->restore();

        $this->assertSame(
            ['created', 'updated', 'deleted', 'restored'],
            $customer->audits()->reorder('id')->pluck('action')->all(),
        );
    }

    public function test_status_changes_are_logged_under_the_person_who_acted(): void
    {
        // Người đang đăng nhập là quản trị viên, nhưng người duyệt là từng cấp trong quy trình.
        $this->actingAs($this->admin);

        $expense = $this->payExpense($this->makeExpense(attributes: ['amount' => 15000000]));

        $changes = AuditLog::query()
            ->where('action', 'status_changed')
            ->where('auditable_type', $expense->getMorphClass())
            ->orderBy('id')
            ->get();

        $this->assertSame($expense->employee->name, $changes->first()->user_name);
        $this->assertSame($this->approver(SystemRole::Accountant)->name, $changes->last()->user_name);
        $this->assertSame('Đề nghị chi '.$expense->code, $changes->last()->label);
        $this->assertStringContainsString('→ Đã thanh toán', $changes->last()->description);
        $this->assertStringContainsString('Số tiền: 15.000.000 ₫', $changes->last()->description);
        $this->assertSame('paid', $changes->last()->new_values['status']);
    }

    public function test_secrets_and_derived_columns_are_not_logged(): void
    {
        $this->actingAs($this->admin);

        $user = User::factory()->create();
        $user->update(['password' => 'mat-khau-moi-123', 'name' => 'Tên Mới']);

        $log = AuditLog::query()->where('action', 'updated')->where('auditable_type', $user->getMorphClass())->sole();

        $this->assertSame('••••••', $log->new_values['password']);
        $this->assertSame('••••••', $log->old_values['password']);
        $this->assertStringNotContainsString('mat-khau-moi-123', json_encode($log->getAttributes()));

        // Thu tiền làm đổi "đã thu" / "còn phải thu" của hóa đơn — hai cột tính lại từ
        // các lần thanh toán nên không ghi; chính lần thanh toán mới là dòng nhật ký.
        $invoice = $this->issuedInvoice();
        $payment = $this->pay($invoice, 1000000);

        $invoiceLogs = AuditLog::query()->where('auditable_type', $invoice->getMorphClass())->get();

        $this->assertFalse($invoiceLogs->contains(fn (AuditLog $log): bool => array_key_exists('remaining_amount', $log->new_values ?? [])
            || array_key_exists('paid_amount', $log->new_values ?? [])));
        $this->assertSame('Thanh toán cho hóa đơn '.$invoice->code, $payment->audits()->sole()->label);
        $this->assertSame('Số tiền: 1.000.000 ₫', $payment->audits()->sole()->description);
    }

    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $log = AuditLogger::record('login', $this->admin, label: 'Người dùng Quản Trị');

        $this->assertNotNull($log);

        try {
            $log->update(['description' => 'sửa']);
            $this->fail('Nhật ký không được phép sửa.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Nhật ký không được phép xoá.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->getKey(), 'description' => null]);

        // Kể cả Super Admin cũng không có nút tạo / sửa / xoá.
        $this->actingAs($this->admin);

        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($log));
        $this->assertFalse(AuditLogResource::canDelete($log));
    }

    public function test_logging_can_be_paused_and_never_breaks_the_business_action(): void
    {
        $this->actingAs($this->admin);

        AuditLogger::withoutLogging(fn () => Customer::factory()->create());

        $this->assertSame(0, AuditLog::query()->where('action', 'created')->where('auditable_type', (new Customer)->getMorphClass())->count());

        // Ghi nhật ký lỗi (ở đây: mất bảng audit_logs) → nghiệp vụ chính vẫn hoàn tất,
        // lỗi chỉ được báo vào log của ứng dụng.
        Exceptions::fake();
        Schema::drop('audit_logs');

        $customer = Customer::factory()->create(['name' => 'Vẫn lưu được']);

        $this->assertDatabaseHas('customers', ['id' => $customer->getKey(), 'name' => 'Vẫn lưu được']);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_successful_login_is_logged(): void
    {
        $this->actingAs($this->admin);
        auth()->logout();

        $this->assertTrue(auth()->attempt(['email' => $this->admin->email, 'password' => 'password']));

        $log = AuditLog::query()->where('action', 'login')->sole();

        $this->assertSame($this->admin->getKey(), $log->user_id);
        $this->assertSame('Người dùng Quản Trị', $log->label);
    }

    public function test_permission_and_role_changes_are_logged(): void
    {
        $this->actingAs($this->admin);

        $role = Role::findByName(SystemRole::Sales->value);
        $before = $role->permissions->pluck('name')->all();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['module_permissions.audit_logs' => ['audit_logs.view']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([...$before, 'audit_logs.view'], $role->refresh()->permissions->pluck('name')->all());

        $log = AuditLog::query()->where('action', 'permissions_updated')->sole();

        $this->assertSame('Vai trò Sales', $log->label);
        $this->assertSame('Thêm 1 quyền, bỏ 0 quyền', $log->description);
        $this->assertSame(['Quyền được thêm' => 'Audit Log — Xem'], $log->new_values);

        // Đổi vai trò của một người dùng.
        $user = $this->createUserWithRole(SystemRole::Employee);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['roles' => [Role::findByName(SystemRole::Accountant->value)->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $roleLog = AuditLog::query()->where('action', 'permissions_updated')->latest('id')->first();

        $this->assertSame('Người dùng '.$user->name, $roleLog->label);
        $this->assertSame(['Vai trò' => 'Employee'], $roleLog->old_values);
        $this->assertSame(['Vai trò' => 'Accountant'], $roleLog->new_values);
    }

    public function test_audit_log_screen_requires_permission_and_filters(): void
    {
        $this->actingAs($this->admin);

        $customer = Customer::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(AuditLogResource::getUrl('index'))
            ->assertForbidden();

        // Giám đốc xem được (có quyền "Xem" mọi module) nhưng không sửa được gì.
        $this->actingAs($this->createUserWithRole(SystemRole::Director))
            ->get(AuditLogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Khách hàng '.$customer->code);

        $customerLog = $customer->audits()->sole();
        $projectLog = $project->audits()->where('action', 'created')->sole();

        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$customerLog, $projectLog])
            ->filterTable('auditable_type', [$customer->getMorphClass()])
            ->assertCanSeeTableRecords([$customerLog])
            ->assertCanNotSeeTableRecords([$projectLog]);
    }

    public function test_record_pages_show_their_own_activity(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);
        $this->actingAs($sales);

        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $customer->update(['phone' => '0909999999']);

        $own = $customer->audits()->get();

        Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($own)
            ->assertCanNotSeeTableRecords($other->audits()->get())
            ->assertCountTableRecords(2);
    }
}
