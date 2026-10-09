<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Enums\SystemRole;
use App\Filament\Resources\Roles\Concerns\SyncsRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Support\Access\AccessCatalog;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    use SyncsRolePermissions;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Role $record): bool => SystemRole::isSystem($record->name)),
        ];
    }

    /**
     * Nạp các quyền hiện có của vai trò vào checkbox theo từng module.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data[RoleForm::PERMISSIONS_FIELD] = AccessCatalog::groupByModule(
            $this->getRecord()->permissions->pluck('name'),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullPermissionsFromFormData($data);
    }

    protected function afterSave(): void
    {
        $this->syncSelectedPermissions();
    }
}
