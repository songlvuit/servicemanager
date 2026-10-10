<?php

namespace App\Filament\Resources\Timesheets\Schemas;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\TaskField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Form ghi nhận giờ công (mục 28), dùng chung cho màn hình Chấm công và tab
 * Chấm công của công việc. Người không có quyền "Xem tất cả" chỉ ghi giờ cho chính mình.
 */
class TimesheetForm
{
    /**
     * @return list<Component>
     */
    public static function components(bool $withTask = true): array
    {
        return [
            Select::make('user_id')
                ->label('Nhân viên')
                ->options(fn (): array => TaskForm::userOptions())
                ->default(fn (): ?int => auth()->id())
                ->searchable()
                ->required()
                // Chỉ người xem được giờ công của mọi người mới ghi hộ người khác.
                ->visible(fn (): bool => Access::can('timesheets.view_all')),
            // Trên trang công việc thì công việc đã xác định sẵn.
            ...($withTask ? [
                TaskField::select()
                    ->placeholder('Không gắn với công việc nào')
                    ->helperText('Giờ công gắn công việc thành chi phí nhân sự của công việc đó; bỏ trống thì là chi phí chung.'),
            ] : []),
            DatePicker::make('work_date')
                ->label('Ngày làm việc')
                ->default(fn (): string => today()->toDateString())
                ->required()
                ->maxDate(today())
                ->native(false)
                ->displayFormat('d/m/Y'),
            TextInput::make('hours')
                ->label('Số giờ')
                ->numeric()
                ->required()
                ->minValue(0.25)
                ->maxValue(24)
                ->step(0.25)
                ->suffix('giờ'),
            TextInput::make('description')
                ->label('Nội dung công việc')
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }
}
