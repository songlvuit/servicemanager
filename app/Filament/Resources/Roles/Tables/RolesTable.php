<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Enums\SystemRole;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Vai trò')
                    ->formatStateUsing(fn (string $state): string => SystemRole::labelFor($state))
                    ->description(fn (Role $record): string => SystemRole::tryFrom($record->name)?->description() ?? 'Vai trò tuỳ chỉnh')
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Loại')
                    ->state(fn (Role $record): string => SystemRole::isSystem($record->name) ? 'Hệ thống' : 'Tuỳ chỉnh')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Hệ thống' ? 'info' : 'gray'),
                TextColumn::make('users_count')
                    ->label('Người dùng')
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label('Số quyền')
                    ->counts('permissions')
                    ->formatStateUsing(fn (Role $record, mixed $state): string => $record->name === SystemRole::SuperAdmin->value
                        ? 'Toàn quyền'
                        : (string) $state)
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Role $record): bool => SystemRole::isSystem($record->name)),
            ])
            ->defaultSort('id')
            ->emptyStateHeading('Chưa có vai trò nào.');
    }
}
