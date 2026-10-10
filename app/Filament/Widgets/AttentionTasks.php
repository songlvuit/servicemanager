<?php

namespace App\Filament\Widgets;

use App\Enums\CostStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Task;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Công việc cần chú ý" (mục 75): công việc còn mở đã quá hạn, và — với người
 * xem được chi phí nội bộ — công việc vượt hoặc sắp vượt ngân sách (kể cả đã
 * xong). Người không xem được chi phí chỉ thấy việc quá hạn, không có cột tiền.
 */
class AttentionTasks extends TableWidget
{
    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Access::can('tasks.view');
    }

    public function table(Table $table): Table
    {
        $seesCosts = Access::canSeeCosts();

        return $table
            ->heading('Công việc cần chú ý')
            ->description($seesCosts
                ? 'Công việc đã quá hạn, hoặc chi phí vượt / sắp vượt ngân sách.'
                : 'Công việc còn mở đã quá hạn.')
            ->query(fn (): Builder => static::attentionQuery($seesCosts))
            ->defaultSort('due_date')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Công việc')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Task $record): ?string => $record->customer?->name)
                    ->wrap(),
                TextColumn::make('assignee.name')
                    ->label('Người phụ trách')
                    ->placeholder('Chưa giao'),
                TextColumn::make('reasons')
                    ->label('Vấn đề')
                    ->state(fn (Task $record): array => static::reasons($record, $seesCosts))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Sắp vượt ngân sách' ? 'warning' : 'danger'),
                Columns::money('budget', 'Ngân sách')
                    ->visible($seesCosts),
                Columns::money('actual_cost', 'Chi phí thực tế')
                    ->visible($seesCosts)
                    ->color(fn (Task $record): string => $record->costStatus()->getColor()),
                TextColumn::make('due_date')
                    ->label('Hạn hoàn thành')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Không có công việc nào cần chú ý')
            ->emptyStateDescription($seesCosts
                ? 'Mọi công việc đều đúng hạn và trong ngân sách.'
                : 'Không có công việc nào quá hạn.');
    }

    /**
     * @return Builder<Task>
     */
    public static function attentionQuery(bool $seesCosts): Builder
    {
        return Task::query()
            ->with(['customer', 'assignee'])
            ->visibleTo(auth()->user())
            ->where(function (Builder $query) use ($seesCosts): void {
                $query->where(fn (Builder $query) => $query->overdue());

                if ($seesCosts) {
                    // Đạt ngưỡng cảnh báo ngân sách (Rule 6) hoặc đã vượt (Rule 7).
                    $query->orWhere(fn (Builder $query) => $query->nearBudget());
                }
            });
    }

    /**
     * @return list<string>
     */
    public static function reasons(Task $task, bool $seesCosts): array
    {
        $reasons = [];

        if ($task->isOverdue()) {
            $reasons[] = 'Quá hạn';
        }

        if ($seesCosts) {
            $reasons[] = match ($task->costStatus()) {
                CostStatus::Over => 'Vượt ngân sách',
                CostStatus::Warning => 'Sắp vượt ngân sách',
                default => null,
            };
        }

        return array_values(array_filter($reasons));
    }
}
