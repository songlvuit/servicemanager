<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ComingSoon;
use App\Support\Access\AccessCatalog;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\original_request;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->brandName(fn (): string => config('app.name'))
            // Màu chủ đạo Navy / Indigo / Blue; màu trạng thái theo mục 40 của yêu cầu.
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Red,
            ])
            ->darkMode()
            ->defaultThemeMode(ThemeMode::System)
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->unsavedChangesAlerts()
            ->navigationGroups(array_map(
                fn (string $group): NavigationGroup => NavigationGroup::make($group),
                AccessCatalog::groups(),
            ))
            ->navigationItems($this->upcomingModuleItems())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Mục menu cho các module chưa triển khai (phase lớn hơn
     * 'completed_phase' trong config/access.php): dẫn tới trang "đang phát
     * triển" và chỉ hiện với người có quyền xem module đó. Module đã triển
     * khai thì có Resource riêng tự đăng ký menu.
     *
     * @return list<NavigationItem>
     */
    private function upcomingModuleItems(): array
    {
        $items = [];

        foreach (AccessCatalog::modules() as $key => $module) {
            if (! $module['menu'] || $module['phase'] <= config('access.completed_phase', 1)) {
                continue;
            }

            $items[] = NavigationItem::make($module['label'])
                ->group($module['group'])
                ->icon($module['icon'])
                ->sort($module['sort'])
                ->url(fn (): string => ComingSoon::getUrl(['module' => $key]))
                ->isActiveWhen(fn (): bool => original_request()->routeIs(ComingSoon::getRouteName())
                    && original_request()->query('module') === $key)
                ->visible(fn (): bool => ComingSoon::canViewModule($key));
        }

        return $items;
    }
}
