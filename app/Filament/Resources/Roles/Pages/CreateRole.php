<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Concerns\SyncsRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use App\Support\Access\AccessCatalog;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use SyncsRolePermissions;

    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->pullPermissionsFromFormData($data);
        $data['guard_name'] = AccessCatalog::guard();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncSelectedPermissions();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
