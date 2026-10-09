<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\ServiceStatus;
use App\Filament\Support\Access;
use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ServiceForm
{
    /**
     * Gợi ý khi nhập; người dùng vẫn gõ được giá trị khác.
     */
    public const CATEGORY_SUGGESTIONS = [
        'Sản xuất nội dung',
        'Sự kiện',
        'Thiết kế',
        'Công nghệ',
        'Tư vấn',
        'Thiết bị',
        'Nhân sự',
    ];

    public const UNIT_SUGGESTIONS = [
        'gói',
        'ngày',
        'buổi',
        'giờ',
        'người',
        'bộ',
        'sản phẩm',
        'tháng',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin dịch vụ')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->description('Mã dịch vụ (SV-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên dịch vụ')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('category')
                            ->label('Nhóm dịch vụ')
                            ->maxLength(100)
                            ->datalist(self::CATEGORY_SUGGESTIONS),
                        TextInput::make('default_unit')
                            ->label('Đơn vị tính')
                            ->maxLength(50)
                            ->datalist(self::UNIT_SUGGESTIONS),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->options(ServiceStatus::class)
                            ->default(ServiceStatus::Active->value)
                            ->required(),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Giá mặc định')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description('Được điền sẵn khi chọn dịch vụ này trong báo giá; vẫn sửa được trên từng báo giá.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Fields::money('default_price')
                            ->label('Đơn giá bán')
                            ->required()
                            ->default(0),
                        // Giá vốn là thông tin nội bộ: chỉ người có quyền mới thấy và sửa.
                        Fields::money('default_cost')
                            ->label('Giá vốn / đơn vị')
                            ->default(0)
                            ->visible(fn (): bool => Access::canSeeCosts()),
                    ]),
            ]);
    }
}
