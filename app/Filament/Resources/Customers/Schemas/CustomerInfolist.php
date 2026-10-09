<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã khách hàng')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('company_type')
                            ->label('Loại hình')
                            ->badge(),
                        TextEntry::make('name')
                            ->label('Tên khách hàng'),
                        TextEntry::make('tax_code')
                            ->label('Mã số thuế')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('industry')
                            ->label('Ngành nghề')
                            ->placeholder('—'),
                    ]),

                Section::make('Liên hệ & địa chỉ')
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('phone')
                            ->label('Điện thoại')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('website')
                            ->label('Website')
                            ->placeholder('—')
                            ->url(
                                fn (?string $state): ?string => preg_match('#^https?://#i', (string) $state) === 1 ? $state : null,
                                shouldOpenInNewTab: true,
                            ),
                        TextEntry::make('address')
                            ->label('Địa chỉ')
                            ->placeholder('—')
                            ->columnSpan(2),
                        TextEntry::make('province')
                            ->label('Tỉnh / Thành phố')
                            ->placeholder('—'),
                    ]),

                Section::make('Ghi chú')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->placeholder('Không có ghi chú.'),
                    ]),

                Section::make('Hệ thống')
                    ->columns(3)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label('Người tạo')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Ngày tạo')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Cập nhật lần cuối')
                            ->dateTime(),
                    ]),
            ]);
    }
}
