<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Task;
use App\Support\Decimal;
use App\Support\Money;
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
                        ? ($record->project !== null ? $record->project->code.' — '.$record->project->name : 'Việc độc lập')
                        : $record->parent?->name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Người phụ trách')
                    ->placeholder('Chưa giao')
                    ->sortable(),
                TextColumn::make('start_date')
                    ->label('Lịch')
                    ->date()
                    ->description(fn (Task $record): ?string => $record->start_date === null ? null : ($record->is_all_day || blank($record->start_time)
                        ? 'Cả ngày'
                        : $record->start_time.' – '.$record->end_time))
                    ->placeholder('Chưa lên lịch')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Ưu tiên')
                    ->badge()
                    ->toggleable(),
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
                // Một cột cho cả hai số: chi phí thực tế ở trên, ngân sách (nếu có đặt) ở dưới.
                Columns::money('actual_cost', 'Chi phí / ngân sách', summarize: true)
                    ->formatStateUsing(fn (Task $record): string => $record->hasBudget() || Decimal::isPositive($record->actual_cost)
                        ? Money::format($record->actual_cost)
                        : '—')
                    ->description(fn (Task $record): ?string => match (true) {
                        $record->isOverBudget() => 'Vượt ngân sách '.Money::format($record->budget),
                        $record->hasBudget() => 'Ngân sách '.Money::format($record->budget),
                        default => null,
                    })
                    ->color(fn (Task $record): ?string => $record->isOverBudget() ? 'danger' : null)
                    ->visible(fn (): bool => Access::canSeeCosts())
                    ->toggleable(),
                Columns::money('budget', 'Ngân sách', summarize: true)
                    ->visible(fn (): bool => Access::canSeeCosts())
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Filters::withoutProject('Chỉ việc độc lập')->visible($withProject),
                Filter::make('overdue')
                    ->label('Chỉ việc quá hạn')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            // Bấm vào dòng để mở trang công việc: ngân sách, chi phí, mua hàng, giờ công, lịch.
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->defaultSort('due_date')
            ->emptyStateHeading('Chưa có công việc nào.')
            ->emptyStateDescription('Tạo công việc (thuộc dự án hoặc việc độc lập), giao người phụ trách và theo dõi tiến độ.');
    }
}
