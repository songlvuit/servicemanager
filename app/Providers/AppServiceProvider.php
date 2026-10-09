<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\Quotations\QuotationCalculator;
use App\Support\Audit\AuditLogger;
use App\Support\Settings;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cơ sở tính lợi nhuận của báo giá lấy từ config/quotation.php.
        $this->app->bind(QuotationCalculator::class, fn (): QuotationCalculator => new QuotationCalculator(
            (string) config('quotation.profit_base', QuotationCalculator::BASE_SUBTOTAL),
        ));
    }

    public function boot(): void
    {
        // Giá trị sửa trong Hệ thống → Cấu hình ghi đè config mặc định.
        Settings::apply();

        $this->configureAuthorization();
        $this->configureAuditLog();
        $this->configureFilamentDefaults();
        $this->configureUploads();
    }

    /**
     * Livewire mặc định chỉ nhận file tạm tới 12MB; nâng lên bằng giới hạn
     * tài liệu đính kèm (config/uploads.php, mặc định 20MB — mục 53).
     */
    private function configureUploads(): void
    {
        config([
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:'.((int) config('uploads.max_size_mb', 20) * 1024),
            ],
        ]);
    }

    /**
     * Ghi nhật ký mỗi lần đăng nhập thành công (mục 39).
     */
    private function configureAuditLog(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                // Lúc sự kiện phát ra, auth()->user() chưa được gán: chỉ rõ người thực hiện.
                AuditLogger::actingAs($event->user, fn () => AuditLogger::record('login', $event->user, label: 'Người dùng '.$event->user->name));
            }
        });
    }

    private function configureAuthorization(): void
    {
        // Super Admin có toàn quyền, không cần gán từng quyền.
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });

        // Model Role thuộc package spatie/laravel-permission nên phải khai báo policy thủ công.
        Gate::policy(Role::class, RolePolicy::class);
    }

    /**
     * Định dạng mặc định cho toàn bộ bảng và form: ngày DD/MM/YYYY, tiền VND.
     */
    private function configureFilamentDefaults(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultDateDisplayFormat('d/m/Y')
                ->defaultDateTimeDisplayFormat('d/m/Y H:i')
                ->defaultCurrency('VND')
                ->defaultNumberLocale('vi');
        });

        Schema::configureUsing(function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat('d/m/Y')
                ->defaultDateTimeDisplayFormat('d/m/Y H:i')
                ->defaultCurrency('VND')
                ->defaultNumberLocale('vi');
        });
    }
}
