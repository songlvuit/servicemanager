<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\SystemRole;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->label('Họ tên')
                    ->description(fn (User $record): ?string => $record->position)
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('roles.name')
                    ->label('Vai trò')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SystemRole::labelFor($state)),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Vai trò')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => SystemRole::labelFor($record->name))
                    ->multiple()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Đã khoá'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (User $record): bool => $record->is(auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            // Không chọn được chính mình và những tài khoản mình không có quyền xoá.
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => ! $record->is(auth()->user())
                && (auth()->user()?->can('delete', $record) ?? false))
            ->defaultSort('name')
            ->emptyStateHeading('Chưa có người dùng nào.')
            ->emptyStateDescription('Tạo người dùng đầu tiên để bắt đầu phân quyền.');
    }
}
