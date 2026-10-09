<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->description('Mã khách hàng (CUS-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên khách hàng')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('company_type')
                            ->label('Loại hình')
                            ->options(CustomerType::class)
                            ->default(CustomerType::Company->value)
                            ->required(),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->options(CustomerStatus::class)
                            ->default(CustomerStatus::Lead->value)
                            ->required(),
                        TextInput::make('tax_code')
                            ->label('Mã số thuế')
                            ->maxLength(30)
                            // Không trùng với khách hàng khác đang hoạt động.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
                            ),
                        TextInput::make('industry')
                            ->label('Ngành nghề')
                            ->maxLength(255),
                    ]),

                Section::make('Liên hệ')
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('phone')
                            ->label('Điện thoại')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('website')
                            ->label('Website')
                            // Chỉ nhận http/https vì giá trị này được hiển thị thành liên kết.
                            ->rule('url:http,https')
                            ->maxLength(255)
                            ->placeholder('https://'),
                    ]),

                Section::make('Địa chỉ')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('address')
                            ->label('Địa chỉ')
                            ->rows(2)
                            ->columnSpan(2),
                        TextInput::make('province')
                            ->label('Tỉnh / Thành phố')
                            ->maxLength(100),
                    ]),

                Section::make('Ghi chú')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(3),
                    ]),
            ]);
    }
}
