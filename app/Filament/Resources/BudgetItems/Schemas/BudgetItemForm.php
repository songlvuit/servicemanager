<?php

namespace App\Filament\Resources\BudgetItems\Schemas;

use App\Enums\BudgetCategory;
use App\Filament\Support\Fields;
use App\Models\BudgetItem;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Component as Livewire;

/**
 * Form một dòng ngân sách, dùng chung cho màn hình Ngân sách và tab Ngân sách
 * của dự án. Người dùng chỉ nhập số kế hoạch; cam kết / thực tế do hệ thống tính.
 */
class BudgetItemForm
{
    /**
     * @return list<Component>
     */
    public static function components(bool $withProject = true): array
    {
        return [
            Select::make('project_id')
                ->label('Dự án')
                ->options(fn (): array => static::projectOptions())
                ->searchable()
                ->required()
                ->disabledOn('edit')
                ->visible($withProject)
                ->columnSpanFull(),
            Select::make('category')
                ->label('Nhóm chi phí')
                ->options(BudgetCategory::class)
                ->required()
                ->rule(fn (Get $get, Livewire $livewire, ?BudgetItem $record): Unique => Rule::unique('budget_items', 'category')
                    ->where('project_id', static::projectId($get, $livewire, $record))
                    ->ignore($record?->getKey()))
                ->validationMessages(['unique' => 'Dự án đã có dòng ngân sách cho nhóm chi phí này.']),
            Fields::money('planned_amount')
                ->label('Ngân sách kế hoạch')
                ->required(),
            TextInput::make('description')
                ->label('Diễn giải')
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /**
     * Dự án đang mở mà người dùng được xem.
     *
     * @return array<int, string>
     */
    public static function projectOptions(): array
    {
        return Project::query()
            ->visibleTo(auth()->user())
            ->orderByDesc('id')
            ->get()
            ->reject(fn (Project $project): bool => $project->isClosed())
            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => "{$project->code} — {$project->name}"])
            ->all();
    }

    private static function projectId(Get $get, Livewire $livewire, ?BudgetItem $record): mixed
    {
        if ($record !== null) {
            return $record->project_id;
        }

        return $livewire instanceof RelationManager
            ? $livewire->getOwnerRecord()->getKey()
            : $get('project_id');
    }
}
