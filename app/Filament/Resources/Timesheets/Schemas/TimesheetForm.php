<?php

namespace App\Filament\Resources\Timesheets\Schemas;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\TaskField;
use App\Models\Timesheet;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Form chấm công theo buổi (mục 28), dùng chung cho màn hình Chấm công và tab
 * Chấm công của công việc. Người không có quyền "Xem tất cả" chỉ chấm công cho
 * chính mình. Phần lớn công do hệ thống tự sinh theo phân công (TaskLabor);
 * form này để nhập thêm hoặc chỉnh lại.
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
                // Chỉ người xem được công của mọi người mới chấm hộ người khác.
                ->visible(fn (): bool => Access::can('timesheets.view_all')),
            // Trên trang công việc thì công việc đã xác định sẵn.
            ...($withTask ? [
                TaskField::select()
                    ->placeholder('Không gắn với công việc nào')
                    ->helperText('Công gắn công việc thành chi phí nhân sự của công việc đó; bỏ trống thì là chi phí chung.'),
            ] : []),
            DatePicker::make('work_date')
                ->label('Ngày làm việc')
                ->default(fn (): string => today()->toDateString())
                ->required()
                // Chấm tay chỉ cho ngày đã tới; công tự sinh của ngày sắp tới vẫn chỉnh được.
                ->maxDate(fn (?Timesheet $record) => $record?->work_date?->isFuture() ? null : today())
                ->native(false)
                ->displayFormat('d/m/Y'),
            TextInput::make('sessions')
                ->label('Số buổi (công)')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(Timesheet::MAX_SESSIONS)
                ->step(0.5)
                ->default(1)
                ->suffix('buổi')
                ->helperText('Một ngày thường 2 buổi (sáng, chiều – tối); 0,5 là nửa buổi. Nhập 0 nếu người này không tính công ngày đó.'),
            // Đơn giá là chi phí nội bộ: chỉ người quản lý công của mọi người và xem được chi phí mới thấy.
            ...(Access::can('timesheets.view_all') && Access::canSeeCosts() ? [
                TextInput::make('session_rate')
                    ->label('Đơn giá / buổi')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999999)
                    ->suffix('₫ / buổi')
                    ->placeholder('Theo đơn giá của nhân viên')
                    ->helperText('Bỏ trống để lấy đơn giá buổi của nhân viên. Nhập số khác nếu việc này trả theo giá riêng.'),
            ] : []),
            TextInput::make('description')
                ->label('Nội dung công việc')
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }
}
