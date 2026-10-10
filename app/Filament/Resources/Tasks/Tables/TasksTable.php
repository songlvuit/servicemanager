<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\ContractLink;
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
 * Bảng công việc, dùng chung cho màn hình Công việc và tab Công việc của hợp đồng.
 * Cột và bộ lọc Hợp đồng chỉ có với người được xem hợp đồng.
 */
class TasksTable
{
    public static function configure(Table $table, bool $withContract = true): Table
    {
        $showContract = $withContract && ContractLink::canSeeContracts();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Công việc')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Task $record): ?string => implode(' · ', array_filter([
                        $record->customer?->name,
                        $record->parent !== null ? 'thuộc: '.$record->parent->name : null,
                    ])) ?: null)
                    ->wrap()
                    // Đủ rộng để tên và khách hàng không bị bẻ thành nhiều dòng ngắn.
                    ->extraCellAttributes(['style' => 'min-width: 18rem'])
                    ->searchable()
                    ->sortable(),
                // Cột hợp đồng chỉ CÓ trong bảng của người được xem hợp đồng (không chỉ ẩn đi).
                ...($showContract ? [
                    TextColumn::make('contract.code')
                        ->label('Hợp đồng')
                        ->placeholder('—')
                        ->toggleable(),
                ] : []),
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
                // Không cộng tổng ở cuối bảng: số của công việc cha đã gồm các công việc con,
                // cộng dồn sẽ trùng — tổng chi phí xem ở Báo cáo chi phí.
                Columns::money('actual_cost', 'Chi phí / ngân sách')
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
                Columns::money('budget', 'Ngân sách')
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
                ...($showContract ? [
                    SelectFilter::make('contract_id')
                        ->label('Hợp đồng')
                        ->options(fn (): array => ContractLink::contractOptions())
                        ->searchable(),
                    Filter::make('without_contract')
                        ->label('Chưa gắn hợp đồng')
                        ->toggle()
                        ->query(fn (Builder $query): Builder => $query->whereNull('contract_id')),
                ] : []),
                Filter::make('overdue')
                    ->label('Chỉ việc quá hạn')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => TaskForm::state($data)),
                DeleteAction::make(),
            ])
            // Bấm vào dòng để mở trang công việc: ngân sách, chi phí, mua hàng, giờ công, lịch.
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->defaultSort('due_date')
            ->emptyStateHeading('Chưa có công việc nào.')
            ->emptyStateDescription('Tạo công việc, giao người phụ trách, đặt ngày để lên lịch và theo dõi tiến độ, chi phí.');
    }
}
