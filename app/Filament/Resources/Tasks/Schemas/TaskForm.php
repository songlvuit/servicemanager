<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Support\Access;
use App\Models\Task;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Component as Livewire;

/**
 * Form công việc, dùng chung cho màn hình Công việc, tab Công việc của dự án
 * và bảng Kanban. Người chỉ có quyền cập nhật công việc được giao (Employee)
 * chỉ sửa được trạng thái và tiến độ.
 */
class TaskForm
{
    /**
     * @return list<Component>
     */
    public static function components(bool $withProject = true): array
    {
        // Người lập kế hoạch công việc (có quyền tạo) mới đổi được nội dung, người phụ trách, thời hạn.
        $locked = fn (): bool => ! Access::can('tasks.create');

        return [
            Select::make('project_id')
                ->label('Dự án')
                ->options(fn (): array => BudgetItemForm::projectOptions())
                ->searchable()
                ->required()
                ->live()
                ->disabledOn('edit')
                ->visible($withProject)
                ->columnSpanFull(),
            TextInput::make('name')
                ->label('Tên công việc')
                ->required()
                ->maxLength(255)
                ->disabled($locked)
                ->columnSpanFull(),
            Select::make('assignee_id')
                ->label('Người phụ trách')
                ->options(fn (): array => static::userOptions())
                ->searchable()
                ->disabled($locked),
            Select::make('parent_id')
                ->label('Công việc cha')
                ->options(fn (Get $get, Livewire $livewire, ?Task $record): array => static::parentOptions($get, $livewire, $record))
                ->searchable()
                ->placeholder('Không có')
                ->disabled($locked),
            Select::make('priority')
                ->label('Mức ưu tiên')
                ->options(TaskPriority::class)
                ->default(TaskPriority::Medium->value)
                ->required()
                ->disabled($locked),
            Select::make('status')
                ->label('Trạng thái')
                ->options(TaskStatus::class)
                ->default(TaskStatus::Todo->value)
                ->required(),
            DatePicker::make('start_date')
                ->label('Ngày bắt đầu')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->disabled($locked),
            DatePicker::make('due_date')
                ->label('Hạn hoàn thành')
                ->afterOrEqual('start_date')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->disabled($locked),
            TextInput::make('estimated_hours')
                ->label('Số giờ dự kiến')
                ->numeric()
                ->minValue(0)
                ->maxValue(9999)
                ->default(0)
                ->suffix('giờ')
                ->disabled($locked),
            TextInput::make('progress')
                ->label('Tiến độ')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->default(0)
                ->suffix('%')
                ->helperText('Công việc "Hoàn thành" tự động là 100%.'),
            Textarea::make('description')
                ->label('Mô tả')
                ->rows(3)
                ->disabled($locked)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function userOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Công việc cùng dự án (trừ chính nó) có thể làm công việc cha.
     *
     * @return array<int, string>
     */
    private static function parentOptions(Get $get, Livewire $livewire, ?Task $record): array
    {
        $projectId = $record?->project_id
            ?? ($livewire instanceof RelationManager ? $livewire->getOwnerRecord()->getKey() : $get('project_id'));

        if (blank($projectId)) {
            return [];
        }

        return Task::query()
            ->where('project_id', $projectId)
            ->whereNull('parent_id')
            ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
