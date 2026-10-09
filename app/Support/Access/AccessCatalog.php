<?php

namespace App\Support\Access;

use Illuminate\Support\Str;

/**
 * Đọc danh mục module & quyền khai báo trong config/access.php.
 */
class AccessCatalog
{
    /**
     * @return array<string, array{label: string, group: string, icon: string, sort: int, phase: int, menu: bool, abilities: list<string>}>
     */
    public static function modules(): array
    {
        return config('access.modules', []);
    }

    /**
     * @return array{label: string, group: string, icon: string, sort: int, phase: int, menu: bool, abilities: list<string>}|null
     */
    public static function module(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        return static::modules()[$key] ?? null;
    }

    public static function guard(): string
    {
        return config('access.guard', 'web');
    }

    /**
     * @return list<string>
     */
    public static function groups(): array
    {
        return config('access.groups', []);
    }

    /**
     * Các module theo từng nhóm sidebar, giữ đúng thứ tự khai báo.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function modulesByGroup(): array
    {
        $grouped = [];

        foreach (static::groups() as $group) {
            $modules = array_filter(
                static::modules(),
                fn (array $module): bool => $module['group'] === $group,
            );

            if ($modules !== []) {
                $grouped[$group] = $modules;
            }
        }

        return $grouped;
    }

    public static function permission(string $module, string $ability): string
    {
        return "{$module}.{$ability}";
    }

    /**
     * Toàn bộ tên quyền của hệ thống.
     *
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        $names = [];

        foreach (static::modules() as $key => $module) {
            foreach ($module['abilities'] as $ability) {
                $names[] = static::permission($key, $ability);
            }
        }

        return $names;
    }

    /**
     * Các quyền của một module, dạng [tên quyền => nhãn ability].
     *
     * @return array<string, string>
     */
    public static function permissionOptions(string $module): array
    {
        $options = [];

        foreach (static::module($module)['abilities'] ?? [] as $ability) {
            $options[static::permission($module, $ability)] = static::abilityLabel($ability);
        }

        return $options;
    }

    public static function abilityLabel(string $ability): string
    {
        return config("access.abilities.{$ability}", Str::headline($ability));
    }

    /**
     * Nhãn đầy đủ của một quyền, ví dụ "Khách hàng — Xem".
     */
    public static function permissionLabel(string $permission): string
    {
        [$module, $ability] = array_pad(explode('.', $permission, 2), 2, '');

        $moduleLabel = static::module($module)['label'] ?? Str::headline($module);

        return "{$moduleLabel} — ".static::abilityLabel($ability);
    }

    /**
     * Chuyển danh sách mẫu ("customers.*", "*.view", "tasks.update") thành
     * danh sách tên quyền cụ thể có trong danh mục.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        if ($patterns === []) {
            return [];
        }

        return array_values(array_filter(
            static::permissionNames(),
            fn (string $name): bool => Str::is($patterns, $name),
        ));
    }

    /**
     * Quyền mặc định của một vai trò hệ thống.
     *
     * @return list<string>
     */
    public static function defaultPermissionsFor(string $role): array
    {
        return static::expand(config("access.role_defaults.{$role}", []));
    }

    /**
     * Gom tên quyền theo module: ['customers' => ['customers.view', ...]].
     *
     * @param  iterable<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function groupByModule(iterable $permissions): array
    {
        $grouped = array_fill_keys(array_keys(static::modules()), []);

        foreach ($permissions as $permission) {
            $module = Str::before($permission, '.');

            if (array_key_exists($module, $grouped)) {
                $grouped[$module][] = $permission;
            }
        }

        return $grouped;
    }
}
