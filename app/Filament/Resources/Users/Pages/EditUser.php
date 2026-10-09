<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var list<string>
     */
    protected array $rolesBeforeSave = [];

    protected function beforeSave(): void
    {
        $this->rolesBeforeSave = $this->getRecord()->roles()->pluck('name')->sort()->values()->all();
    }

    /**
     * Đổi vai trò của một người là đổi quyền của họ: ghi vào nhật ký thao tác.
     */
    protected function afterSave(): void
    {
        $user = $this->getRecord();
        $after = $user->roles()->pluck('name')->sort()->values()->all();

        if ($after === $this->rolesBeforeSave) {
            return;
        }

        AuditLogger::record(
            'permissions_updated',
            $user,
            label: 'Người dùng '.$user->name,
            description: 'Đổi vai trò',
            old: ['Vai trò' => implode(', ', $this->rolesBeforeSave)],
            new: ['Vai trò' => implode(', ', $after)],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (User $record): bool => $record->is(auth()->user())),
        ];
    }
}
