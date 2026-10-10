<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Pages\ComingSoon;
use App\Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_upcoming_module_page_is_available_to_permitted_roles(): void
    {
        // Trang giữ chỗ chỉ dành cho module chưa triển khai: giả lập hệ thống đang ở Phase 2.
        config(['access.completed_phase' => 2]);

        $accountant = $this->createUserWithRole(SystemRole::Accountant);

        $this->actingAs($accountant)
            ->get(ComingSoon::getUrl(['module' => 'contracts']))
            ->assertOk()
            ->assertSee('Hợp đồng');
    }

    public function test_implemented_modules_no_longer_have_a_placeholder_page(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);

        // Khách hàng đã có màn hình thật từ Phase 2.
        $this->actingAs($sales)
            ->get(ComingSoon::getUrl(['module' => 'customers']))
            ->assertNotFound();
    }

    public function test_upcoming_module_page_is_forbidden_without_permission(): void
    {
        config(['access.completed_phase' => 2]);

        $sales = $this->createUserWithRole(SystemRole::Sales);

        $this->actingAs($sales)
            ->get(ComingSoon::getUrl(['module' => 'audit_logs']))
            ->assertForbidden();
    }

    public function test_unknown_module_returns_not_found(): void
    {
        $admin = $this->createUserWithRole(SystemRole::SuperAdmin);

        $this->actingAs($admin)
            ->get(ComingSoon::getUrl(['module' => 'khong-ton-tai']))
            ->assertNotFound();
    }

    public function test_sidebar_only_lists_modules_the_user_may_view(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);

        $this->actingAs($sales)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Cơ hội')
            // Báo giá, hợp đồng chỉ Giám đốc và Kế toán thấy — kể cả trên bảng điều khiển.
            ->assertDontSee('Báo giá')
            ->assertDontSee('Hợp đồng')
            ->assertDontSee('Dự án')
            ->assertDontSee('Nhà cung cấp')
            ->assertDontSee('Audit Log');
    }

    public function test_accountant_sees_quotations_and_contracts_in_the_sidebar(): void
    {
        $this->actingAs($this->createUserWithRole(SystemRole::Accountant))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Báo giá')
            ->assertSee('Hợp đồng')
            ->assertSee('Công việc')
            ->assertDontSee('Dự án');
    }

    public function test_dashboard_greeting_follows_the_time_of_day(): void
    {
        $this->assertSame('Chào buổi sáng', Dashboard::greeting(8));
        $this->assertSame('Chào buổi trưa', Dashboard::greeting(12));
        $this->assertSame('Chào buổi chiều', Dashboard::greeting(15));
        $this->assertSame('Chào buổi tối', Dashboard::greeting(21));
    }
}
