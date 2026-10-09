<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\SystemRole;
use App\Support\Access\AccessCatalog;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;

class RoleForm
{
    /**
     * Tên trường chứa quyền đã chọn, dạng ['customers' => ['customers.view', ...]].
     * Trường này không phải cột của bảng roles: các trang Create/Edit tách nó
     * ra và đồng bộ quyền sau khi lưu (xem Concerns\SyncsRolePermissions).
     */
    public const PERMISSIONS_FIELD = 'module_permissions';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Vai trò')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên vai trò')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record): bool => SystemRole::isSystem($record?->name))
                            ->helperText(fn (?Role $record): ?string => static::nameHelperText($record)),
                    ]),

                Section::make('Quyền theo module')
                    ->icon(Heroicon::OutlinedKey)
                    ->description('Chọn những thao tác mà vai trò này được phép thực hiện trên từng module.')
                    ->columnSpanFull()
                    ->hidden(fn (?Role $record): bool => $record?->name === SystemRole::SuperAdmin->value)
                    ->schema(static::permissionFieldsets()),
            ]);
    }

    private static function nameHelperText(?Role $record): ?string
    {
        $systemRole = SystemRole::tryFrom($record?->name ?? '');

        if ($systemRole === null) {
            return null;
        }

        $text = "Vai trò hệ thống ({$systemRole->label()}): không đổi tên và không xoá được.";

        if ($systemRole === SystemRole::SuperAdmin) {
            $text .= ' Super Admin luôn có toàn quyền nên không cần chọn quyền.';
        }

        return $text;
    }

    /**
     * Mỗi nhóm sidebar là một khung, mỗi module là một danh sách checkbox.
     *
     * @return list<Fieldset>
     */
    private static function permissionFieldsets(): array
    {
        $fieldsets = [];

        foreach (AccessCatalog::modulesByGroup() as $group => $modules) {
            $lists = [];

            foreach ($modules as $key => $module) {
                if ($module['abilities'] === []) {
                    continue;
                }

                $lists[] = CheckboxList::make(static::PERMISSIONS_FIELD.'.'.$key)
                    ->label($module['label'])
                    ->options(AccessCatalog::permissionOptions($key))
                    ->columns(2)
                    ->bulkToggleable();
            }

            if ($lists !== []) {
                $fieldsets[] = Fieldset::make($group)
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema($lists);
            }
        }

        return $fieldsets;
    }
}
