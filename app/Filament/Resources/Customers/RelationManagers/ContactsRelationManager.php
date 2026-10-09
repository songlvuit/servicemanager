<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\CustomerContact;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Liên hệ';

    protected static ?string $modelLabel = 'liên hệ';

    protected static ?string $pluralModelLabel = 'liên hệ';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    /**
     * Cho phép thêm / sửa liên hệ ngay trên trang chi tiết khách hàng.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Họ tên')
                    ->required()
                    ->maxLength(255),
                TextInput::make('position')
                    ->label('Chức vụ')
                    ->maxLength(255),
                TextInput::make('department')
                    ->label('Phòng ban')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Điện thoại')
                    ->tel()
                    ->maxLength(30),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),
                Toggle::make('is_primary')
                    ->label('Liên hệ chính')
                    ->helperText('Mỗi khách hàng có một liên hệ chính; chọn người này sẽ bỏ chọn người đang là liên hệ chính.'),
                Textarea::make('notes')
                    ->label('Ghi chú')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Họ tên')
                    ->weight(FontWeight::Medium)
                    ->description(fn (CustomerContact $record): ?string => $record->position)
                    ->searchable(),
                IconColumn::make('is_primary')
                    ->label('Liên hệ chính')
                    ->boolean(),
                TextColumn::make('department')
                    ->label('Phòng ban')
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Chưa có liên hệ nào.')
            ->emptyStateDescription('Thêm người liên hệ của khách hàng này.');
    }
}
