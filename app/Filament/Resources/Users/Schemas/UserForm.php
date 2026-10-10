<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\SystemRole;
use App\Filament\Support\Fields;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin tài khoản')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Họ tên')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email đăng nhập')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('phone')
                            ->label('Điện thoại')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('position')
                            ->label('Chức danh')
                            ->maxLength(255),
                        Fields::money('session_rate')
                            ->label('Đơn giá / buổi (công)')
                            ->default(0)
                            ->suffix('₫ / buổi')
                            ->helperText('Chi phí nhân sự của công việc = số buổi được phân công × đơn giá này. Để 0 nếu không tính. Đổi đơn giá chỉ áp dụng cho công từ hôm nay trở đi.'),
                    ]),

                Section::make('Mật khẩu')
                    ->icon(Heroicon::OutlinedKey)
                    ->description(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Bỏ trống nếu không muốn đổi mật khẩu.'
                        : null)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->label('Mật khẩu')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rule(Password::min(8))
                            ->same('password_confirmation')
                            // Chỉ lưu khi có nhập; model User tự băm mật khẩu (cast "hashed").
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                        TextInput::make('password_confirmation')
                            ->label('Nhập lại mật khẩu')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(false),
                    ]),

                Section::make('Phân quyền')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('roles')
                            ->label('Vai trò')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                // Chỉ Super Admin mới được gán vai trò Super Admin.
                                modifyQueryUsing: fn (Builder $query): Builder => auth()->user()?->isSuperAdmin()
                                    ? $query
                                    : $query->where('name', '!=', SystemRole::SuperAdmin->value),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Role $record): string => SystemRole::labelFor($record->name))
                            ->multiple()
                            ->preload()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Đang hoạt động')
                            ->helperText('Tắt để khoá tài khoản: người dùng sẽ không đăng nhập được.')
                            ->default(true)
                            // Không tự khoá chính mình.
                            ->disabled(fn (?User $record): bool => $record !== null && $record->is(auth()->user())),
                    ]),
            ]);
    }
}
