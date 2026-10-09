<?php

namespace App\Filament\Resources\Timesheets\Schemas;

use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Models\Task;
use App\Models\Timesheet;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Livewire\Component as Livewire;

/**
 * Form ghi nhận giờ công (mục 28), dùng chung cho màn hình Chấm công và tab
 * Chấm công của dự án. Người không có quyền "Xem tất cả" chỉ ghi giờ cho chính mình.
 */
class TimesheetForm
{
    /**
     * @return list<Component>
     */
    public static function components(bool $withProject = true): array
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
            Select::make('project_id')
                ->label('Dự án')
                ->options(fn (): array => BudgetItemForm::projectOptions())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('task_id', null))
                ->visible($withProject),
            Select::make('task_id')
                ->label('Công việc')
                ->options(fn (Get $get, Livewire $livewire, ?Timesheet $record): array => static::taskOptions($get, $livewire, $record))
                ->searchable()
                ->placeholder('Không gắn với công việc nào'),
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

    /**
     * Công việc của dự án đang chọn mà người dùng được xem.
     *
     * @return array<int, string>
     */
    private static function taskOptions(Get $get, Livewire $livewire, ?Timesheet $record): array
    {
        $projectId = $livewire instanceof RelationManager
            ? $livewire->getOwnerRecord()->getKey()
            : ($get('project_id') ?? $record?->project_id);

        if (blank($projectId)) {
            return [];
        }

        return Task::query()
            ->where('project_id', $projectId)
            ->visibleTo(auth()->user())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
