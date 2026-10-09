<?php

namespace App\Filament\Pages;

use App\Support\Access\AccessCatalog;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang giữ chỗ cho các module chưa triển khai, để sidebar thể hiện đầy đủ
 * cấu trúc hệ thống ngay từ Phase 1. URL: /admin/sap-ra-mat?module=customers
 */
class ComingSoon extends Page
{
    protected static ?string $slug = 'sap-ra-mat';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.coming-soon';

    public ?string $module = null;

    public function mount(): void
    {
        $module = request()->query('module');
        $this->module = is_string($module) ? $module : null;

        $definition = AccessCatalog::module($this->module);

        // Module không tồn tại, hoặc đã triển khai (có màn hình riêng) thì không có trang giữ chỗ.
        abort_if($definition === null || $definition['phase'] <= config('access.completed_phase', 1), 404);
        abort_unless(static::canViewModule($this->module), 403);
    }

    /**
     * Module không khai báo quyền (abilities rỗng) thì ai cũng xem được.
     */
    public static function canViewModule(string $module): bool
    {
        $definition = AccessCatalog::module($module);

        if ($definition === null) {
            return false;
        }

        if ($definition['abilities'] === []) {
            return true;
        }

        return auth()->user()?->can(AccessCatalog::permission($module, 'view')) ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return AccessCatalog::module($this->module)['label'] ?? 'Đang phát triển';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $definition = AccessCatalog::module($this->module);

        return [
            'label' => $definition['label'] ?? '',
            'phase' => $definition['phase'] ?? null,
        ];
    }
}
