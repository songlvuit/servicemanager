<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Models\ProjectMember;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Tab "Thành viên": nhân sự tham gia dự án. Thành viên xem được dự án kể cả
 * khi không có quyền xem tất cả dự án.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Thành viên';

    protected static ?string $modelLabel = 'thành viên';

    protected static ?string $pluralModelLabel = 'thành viên';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Nhân sự')
                    ->options(fn (): array => TaskForm::userOptions())
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->rule(fn (?ProjectMember $record): Unique => Rule::unique('project_members', 'user_id')
                        ->where('project_id', $this->getOwnerRecord()->getKey())
                        ->ignore($record?->getKey()))
                    ->validationMessages(['unique' => 'Người này đã là thành viên của dự án.']),
                TextInput::make('role')
                    ->label('Vai trò trong dự án')
                    ->placeholder('Ví dụ: Đạo diễn, Quay phim, Thiết kế')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Họ tên')
                    ->weight(FontWeight::Medium)
                    ->description(fn (ProjectMember $record): ?string => $record->user?->position)
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Vai trò trong dự án')
                    ->placeholder('—'),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->copyable(),
                TextColumn::make('created_at')
                    ->label('Tham gia từ')
                    ->date(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm thành viên')
                    ->modalHeading('Thêm thành viên'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Gỡ')
                    ->modalHeading('Gỡ thành viên khỏi dự án?'),
            ])
            ->emptyStateHeading('Chưa có thành viên nào.')
            ->emptyStateDescription('Thêm nhân sự tham gia dự án.');
    }
}
