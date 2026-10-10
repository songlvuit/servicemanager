<?php

namespace App\Filament\Resources\ScheduleTypes;

use App\Filament\Resources\ScheduleTypes\Pages\ManageScheduleTypes;
use App\Filament\Support\VietnameseLabels;
use App\Models\ScheduleType;
use App\Support\ScheduleColors;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Danh mục loại lịch (quay phim, livestream, họp, nghỉ phép…): tên, màu trên
 * lịch và thứ tự hiển thị.
 */
class ScheduleTypeResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = ScheduleType::class;

    protected static ?string $slug = 'schedule-types';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Loại lịch';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'loại lịch';

    protected static ?string $pluralModelLabel = 'loại lịch';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tên loại lịch')
                    ->placeholder('Ví dụ: Livestream')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),
                Select::make('color')
                    ->label('Màu trên lịch')
                    ->options(ScheduleColors::options())
                    ->default(ScheduleColors::DEFAULT)
                    ->required()
                    ->native(false),
                TextInput::make('sort_order')
                    ->label('Thứ tự')
                    ->helperText('Số nhỏ hiện trước.')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->default(0)
                    ->required(),
                Toggle::make('is_leave')
                    ->label('Là lịch nghỉ / bận')
                    ->helperText('Lịch thuộc loại này không phải việc của khách: không tạo công việc, hiện thành dải "Nghỉ" trên lịch, và nút "Đăng ký nghỉ" dùng loại này.')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Đang dùng')
                    ->helperText('Tắt để không chọn được loại này cho lịch mới; lịch cũ vẫn giữ nguyên.')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Loại lịch')
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('color')
                    ->label('Màu')
                    ->formatStateUsing(fn (ScheduleType $record): HtmlString => new HtmlString(
                        '<span style="display:inline-flex;align-items:center;gap:.5rem">'
                        .'<span style="width:.75rem;height:.75rem;border-radius:999px;background:'.e($record->hex()).'"></span>'
                        .e(ScheduleColors::label($record->color)).'</span>'
                    )),
                IconColumn::make('is_leave')
                    ->label('Lịch nghỉ')
                    ->boolean(),
                TextColumn::make('entries_count')
                    ->label('Số lịch')
                    ->counts('entries')
                    ->alignEnd(),
                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Đang dùng')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order')
            ->paginated(false)
            ->emptyStateHeading('Chưa có loại lịch nào.')
            ->emptyStateDescription('Thêm các loại việc công ty thường làm để phân biệt bằng màu trên lịch.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScheduleTypes::route('/'),
        ];
    }
}
