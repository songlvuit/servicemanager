<?php

namespace Tests\Feature;

use App\Enums\CostStatus;
use App\Enums\MarginBand;
use App\Enums\SystemRole;
use App\Filament\Pages\SystemSettings;
use App\Models\AuditLog;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hệ thống → Cấu hình: các ngưỡng nghiệp vụ sửa được trên giao diện.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_saved_settings_override_the_defaults_and_drive_business_rules(): void
    {
        // Mặc định cảnh báo ngân sách ở 80%: chi 75/100 vẫn "trong ngân sách".
        $this->assertSame(CostStatus::Ok, CostStatus::for(100, 75));
        $this->assertSame(MarginBand::Normal, MarginBand::for(25));

        Settings::save([
            'project.budget_warning_threshold' => '70',
            'project.margin_good' => 24,
            'company.name' => '  Công ty TNHH Dịch Vụ Sáng Tạo  ',
        ]);

        $this->assertSame(70.0, config('project.budget_warning_threshold'));
        $this->assertSame('Công ty TNHH Dịch Vụ Sáng Tạo', config('company.name'));
        $this->assertSame(CostStatus::Warning, CostStatus::for(100, 75));
        $this->assertSame(MarginBand::Good, MarginBand::for(25));

        // Lần khởi động sau (config trở về mặc định) giá trị đã lưu được nạp lại.
        config(['project.budget_warning_threshold' => 80.0, 'company.name' => null]);
        Cache::forget(Settings::CACHE_KEY);

        Settings::apply();

        $this->assertSame(70.0, config('project.budget_warning_threshold'));
        $this->assertSame('Công ty TNHH Dịch Vụ Sáng Tạo', config('company.name'));
        $this->assertSame(70.0, Settings::current()['project.budget_warning_threshold']);
    }

    public function test_only_declared_keys_can_be_saved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Settings::save(['app.debug' => true]);
    }

    public function test_changes_are_audited_once_and_unchanged_values_are_ignored(): void
    {
        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        Settings::save(['billing.payment_term_days' => 45, 'billing.default_tax_rate' => config('billing.default_tax_rate')]);

        $log = AuditLog::query()->where('action', 'settings_updated')->sole();

        $this->assertSame('Đổi: Hạn thanh toán mặc định (ngày)', $log->description);
        $this->assertSame(['billing.payment_term_days' => 30], $log->old_values);
        $this->assertSame(['billing.payment_term_days' => 45], $log->new_values);

        Settings::save(['billing.payment_term_days' => 45]);

        $this->assertSame(1, AuditLog::query()->where('action', 'settings_updated')->count());
    }

    public function test_settings_page_saves_through_the_form(): void
    {
        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->get(SystemSettings::getUrl())->assertOk()->assertSee('Thông tin công ty')->assertSee('Lưu cấu hình');

        Livewire::test(SystemSettings::class)
            ->assertFormSet(['quotation.validity_days' => config('quotation.validity_days')])
            ->fillForm([
                'company.name' => 'Công ty Mẫu',
                'quotation.low_margin_threshold' => 18,
                'billing.due_reminder_days' => 5,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Đã lưu cấu hình');

        $this->assertSame('Công ty Mẫu', config('company.name'));
        $this->assertSame(18.0, config('quotation.low_margin_threshold'));
        $this->assertSame(5, config('billing.due_reminder_days'));
        $this->assertDatabaseHas('settings', ['key' => 'billing.due_reminder_days', 'value' => '5']);
    }

    public function test_settings_page_validates_values(): void
    {
        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        Livewire::test(SystemSettings::class)
            ->fillForm(['billing.default_tax_rate' => 150, 'quotation.validity_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['billing.default_tax_rate', 'quotation.validity_days']);

        // Ba ngưỡng biên lợi nhuận phải tăng dần.
        Livewire::test(SystemSettings::class)
            ->fillForm(['project.margin_danger' => 25, 'project.margin_warning' => 20])
            ->call('save')
            ->assertNotified('Chưa lưu được cấu hình');

        $this->assertSame(10.0, config('project.margin_danger'));
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_settings_page_access_follows_permissions(): void
    {
        // Nhân viên: không vào được.
        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get(SystemSettings::getUrl())
            ->assertForbidden();

        // Giám đốc: xem được nhưng không có quyền sửa.
        $this->actingAs($this->createUserWithRole(SystemRole::Director));

        $this->get(SystemSettings::getUrl())->assertOk()->assertDontSee('Lưu cấu hình');

        Livewire::test(SystemSettings::class)
            ->fillForm(['billing.payment_term_days' => 60])
            ->call('save')
            ->assertForbidden();

        $this->assertSame(30, config('billing.payment_term_days'));
    }
}
