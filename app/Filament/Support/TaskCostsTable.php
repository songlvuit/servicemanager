<?php

namespace App\Filament\Support;

use App\Enums\BudgetCategory;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskCostItem;
use App\Support\Money;
use Closure;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Bảng "mọi chi phí của công việc": đề nghị chi, mua hàng và công của nhân sự
 * trên cùng một danh sách, gom theo nhóm chi phí (Nhân sự, Thiết bị, Nhà cung
 * cấp, Đi lại…), có tổng từng nhóm. Dùng cho tab "Chi phí" của công việc và
 * trang "Chi phí theo công việc".
 *
 * Toàn bộ là số tiền nội bộ: chỉ đưa bảng này cho người được xem chi phí nội bộ.
 */
class TaskCostsTable
{
    /**
     * @param  bool  $withTask  Hiện cột / nhóm / bộ lọc Công việc (trang tổng hợp, hoặc công việc có việc con).
     */
    public static function configure(Table $table, bool $withTask = true): Table
    {
        $money = fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0);

        return $table
            ->modelLabel('khoản chi phí')
            ->pluralModelLabel('khoản chi phí')
            ->columns([
                TextColumn::make('cost_date')
                    ->label('Ngày')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Nhóm chi phí')
                    ->badge()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Nội dung')
                    ->state(fn (TaskCostItem $record): string => filled($record->description) ? (string) $record->description : $record->sourceLabel())
                    ->description(fn (TaskCostItem $record): string => trim($record->sourceLabel().' '.($record->reference ?? '')))
                    ->url(fn (TaskCostItem $record): ?string => static::canOpen($record) ? $record->sourceUrl() : null)
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable(['description', 'reference']),
                TextColumn::make('party')
                    ->label('Nhà cung cấp / nhân sự')
                    ->state(fn (TaskCostItem $record): ?string => $record->partyName())
                    ->description(fn (TaskCostItem $record): ?string => $record->partyDetail())
                    ->placeholder('—')
                    ->wrap(),
                ...($withTask ? [
                    TextColumn::make('task.name')
                        ->label('Công việc')
                        ->placeholder('Chi phí chung')
                        ->url(fn (TaskCostItem $record): ?string => $record->task !== null && (auth()->user()?->can('view', $record->task) ?? false)
                            ? TaskResource::getUrl('view', ['record' => $record->task])
                            : null)
                        ->wrap()
                        ->toggleable(),
                ] : []),
                TextColumn::make('amount')
                    ->label('Số tiền (trước thuế)')
                    ->formatStateUsing($money)
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd()
                    ->sortable()
                    ->summarize([
                        Sum::make()->label('Tổng')->formatStateUsing($money),
                        // Phần đã thành chi phí thực tế (đã thanh toán, đã nhận hàng, công của nhân sự).
                        Summarizer::make()
                            ->label('Trong đó thực tế')
                            ->using(fn (QueryBuilder $query): string => (string) ((clone $query)->where('is_actual', 1)->sum('amount') ?: 0))
                            ->formatStateUsing($money),
                    ]),
                TextColumn::make('is_actual')
                    ->label('Tình trạng')
                    ->badge()
                    ->formatStateUsing(fn (TaskCostItem $record): string => $record->stageLabel())
                    ->color(fn (TaskCostItem $record): string => $record->is_actual ? 'success' : 'warning')
                    ->tooltip(fn (TaskCostItem $record): string => $record->is_actual
                        ? 'Đã thanh toán, đã nhận hàng, hoặc là công của nhân sự.'
                        : 'Đã duyệt / đã đặt hàng nhưng chưa thanh toán, chưa nhận hàng.'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Nhóm chi phí')
                    ->options(BudgetCategory::class)
                    ->multiple(),
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(TaskCostItem::SOURCES)
                    ->multiple(),
                SelectFilter::make('is_actual')
                    ->label('Tình trạng')
                    ->options([1 => 'Thực tế', 0 => 'Đã cam kết (chưa chi)']),
                ...($withTask ? [
                    SelectFilter::make('task_id')
                        ->label('Công việc')
                        ->options(fn (): array => Task::query()
                            ->visibleTo(auth()->user())
                            ->whereIn('id', TaskCostItem::query()->whereNotNull('task_id')->select('task_id'))
                            ->orderByDesc('id')
                            ->limit(500)
                            ->get(['id', 'name', 'start_date'])
                            ->mapWithKeys(fn (Task $task): array => [$task->getKey() => TaskField::label($task)])
                            ->all())
                        ->searchable()
                        ->multiple(),
                    Filters::withoutTask('Chỉ chi phí chung (không gắn công việc)'),
                ] : []),
                Filters::dateRange('cost_date', 'Ngày'),
            ])
            ->groups([
                Group::make('category')
                    ->label('Nhóm chi phí')
                    ->getTitleFromRecordUsing(fn (TaskCostItem $record): string => $record->category?->getLabel() ?? 'Khác')
                    ->collapsible(),
                Group::make('source')
                    ->label('Nguồn')
                    ->getTitleFromRecordUsing(fn (TaskCostItem $record): string => $record->sourceLabel())
                    ->collapsible(),
                ...($withTask ? [
                    Group::make('task.name')
                        ->label('Công việc')
                        ->getTitleFromRecordUsing(fn (TaskCostItem $record): string => $record->task?->name ?? 'Chi phí chung')
                        ->collapsible(),
                ] : []),
            ])
            ->defaultSort('cost_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('Chưa có khoản chi phí nào.')
            ->emptyStateDescription('Chi phí xuất hiện khi có người được phân công (công của nhân sự), đề nghị chi được duyệt, hoặc hàng được đặt.');
    }

    /**
     * Các cột của file xuất (dùng với TableExport).
     *
     * @return Closure(): array<string, string|Closure>
     */
    public static function exportColumns(): Closure
    {
        return fn (): array => [
            'Ngày' => 'cost_date',
            'Công việc' => fn (TaskCostItem $item): string => $item->task?->name ?? 'Chi phí chung',
            'Nhóm chi phí' => 'category',
            'Nguồn' => fn (TaskCostItem $item): string => $item->sourceLabel(),
            'Số chứng từ' => 'reference',
            'Nội dung' => 'description',
            'Nhà cung cấp / nhân sự' => fn (TaskCostItem $item): ?string => $item->partyName(),
            'Số buổi (công)' => 'sessions',
            'Đơn giá / buổi' => 'unit_price',
            'Số tiền (trước thuế)' => 'amount',
            'Tình trạng' => fn (TaskCostItem $item): string => $item->stageLabel(),
        ];
    }

    /**
     * Người xem có mở được chứng từ gốc không.
     */
    private static function canOpen(TaskCostItem $item): bool
    {
        return match ($item->source) {
            'expense' => Access::can('expenses.view') && (Access::can('expenses.view_all') || $item->user_id === auth()->id()),
            'purchase_request', 'purchase_order' => Access::can('purchases.view'),
            default => false,
        };
    }
}
