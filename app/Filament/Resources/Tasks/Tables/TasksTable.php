<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Models\Task;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bảng công việc, dùng chung cho màn hình Công việc và tab Công việc của dự án.
 */
class TasksTable
{
    public static function configure(Table $table, bool $withProject = true): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Công việc')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Task $record): ?string => $withProject
                        ? trim(($record->project?->code ?? '').' — '.($record->project?->name ?? ''), ' —')
                        : $record->parent?->name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Người phụ trách')
                    ->placeholder('Chưa giao')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Ưu tiên')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('due_date')
                    ->label('Hạn')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('estimated_hours')
                    ->label('Giờ dự kiến')
                    ->numeric(decimalPlaces: 1)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actual_hours')
                    ->label('Giờ thực tế')
                    ->numeric(decimalPlaces: 1)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(TaskStatus::class)
                    ->multiple(),
                SelectFilter::make('priority')
                    ->label('Mức ưu tiên')
                    ->options(TaskPriority::class)
                    ->multiple(),
                SelectFilter::make('assignee_id')
                    ->label('Người phụ trách')
                    ->options(fn (): array => TaskForm::userOptions())
                    ->searchable(),
                Filter::make('overdue')
                    ->label('Chỉ việc quá hạn')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('due_date')
            ->emptyStateHeading('Chưa có công việc nào.')
            ->emptyStateDescription('Chia dự án thành các công việc, giao người phụ trách và theo dõi tiến độ.');
    }
}
