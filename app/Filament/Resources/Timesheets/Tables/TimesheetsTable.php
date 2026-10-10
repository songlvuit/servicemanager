<?php

namespace App\Filament\Resources\Timesheets\Tables;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TaskField;
use App\Models\Task;
use App\Models\Timesheet;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bảng công theo buổi, dùng chung cho màn hình Chấm công và tab Chấm công của
 * công việc. Cột đơn giá, chi phí chỉ có với người được xem chi phí nội bộ.
 */
class TimesheetsTable
{
    public static function configure(Table $table, bool $withTask = true): Table
    {
        $filters = [
            SelectFilter::make('user_id')
                ->label('Nhân viên')
                ->options(fn (): array => TaskForm::userOptions())
                ->searchable()
                ->multiple()
                ->visible(fn (): bool => Access::can('timesheets.view_all')),
            // Chọn một tháng để xem tổng công của tháng đó (dòng "Tổng" ở cuối bảng, hoặc gom theo nhân viên).
            // Màn hình Chấm công mở ra ở tháng hiện tại.
            SelectFilter::make('month')
                ->label('Tháng')
                ->options(fn (): array => static::monthOptions())
                ->default($withTask ? today()->format('Y-m') : null)
                ->query(fn (Builder $query, array $data): Builder => static::applyMonth($query, $data['value'] ?? null)),
            Filters::dateRange('work_date', 'Ngày làm việc'),
            SelectFilter::make('is_auto')
                ->label('Nguồn')
                ->options([1 => 'Tự động (theo phân công)', 0 => 'Nhập tay']),
        ];

        if ($withTask) {
            array_unshift(
                $filters,
                SelectFilter::make('task_id')
                    ->label('Công việc')
                    ->options(fn (): array => static::taskOptions())
                    ->searchable()
                    ->multiple(),
                Filters::withoutTask(),
            );
        }

        return $table
            ->columns([
                TextColumn::make('work_date')
                    ->label('Ngày')
                    ->date()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Nhân viên')
                    ->searchable(),
                TextColumn::make('task.name')
                    ->label('Công việc')
                    ->placeholder('Chi phí chung')
                    ->wrap()
                    ->searchable()
                    ->visible($withTask),
                TextColumn::make('description')
                    ->label('Nội dung')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('sessions')
                    ->label('Số buổi')
                    ->formatStateUsing(fn (mixed $state): string => static::sessions($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Tổng buổi')->formatStateUsing(fn (mixed $state): string => static::sessions($state))),
                ...(Access::canSeeCosts() ? [
                    Columns::money('session_rate', 'Đơn giá / buổi')
                        ->toggleable(),
                    Columns::money('cost', 'Chi phí nhân sự', summarize: true),
                ] : []),
                TextColumn::make('is_auto')
                    ->label('Nguồn')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Tự động' : 'Nhập tay')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'info')
                    ->tooltip(fn (bool $state): string => $state
                        ? 'Tự tính theo phân công của công việc; đổi người, ngày, giờ của công việc thì công đổi theo.'
                        : 'Nhập hoặc chỉnh tay; hệ thống không tự đổi dòng này.')
                    ->toggleable(),
            ])
            ->groups([
                Group::make('user.name')->label('Nhân viên')->collapsible(),
                ...($withTask ? [
                    Group::make('task.name')
                        ->label('Công việc')
                        ->getTitleFromRecordUsing(fn (Timesheet $record): string => $record->task?->name ?? 'Chi phí chung (không gắn công việc)')
                        ->collapsible(),
                ] : []),
                Group::make('work_date')->label('Ngày')->date('d/m/Y')->collapsible(),
            ])
            ->filters($filters)
            ->recordActions([
                EditAction::make(),
                // Dòng tự sinh xoá đi sẽ được sinh lại theo phân công: muốn bỏ thì sửa số buổi về 0.
                DeleteAction::make()
                    ->hidden(fn (Timesheet $record): bool => $record->is_auto),
            ])
            ->defaultSort('work_date', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Chưa có công nào.')
            ->emptyStateDescription('Công tự sinh khi một người được phân công vào công việc có ngày bắt đầu; chi phí nhân sự = số buổi × đơn giá buổi.');
    }

    /**
     * Số buổi viết gọn kiểu Việt Nam: "2", "1,5", "79,75".
     */
    public static function sessions(mixed $value): string
    {
        return str_replace('.', ',', Decimal::trimZeros(Decimal::round(is_numeric($value) ? (string) $value : '0')) ?? '0');
    }

    /**
     * Các tháng có thể chọn: từ tháng có dòng công đầu tiên tới tháng có dòng
     * công cuối cùng (ít nhất là tháng này), mới nhất ở trên.
     *
     * @return array<string, string> "2026-10" => "Tháng 10/2026"
     */
    public static function monthOptions(): array
    {
        $user = auth()->user();
        $visible = fn (): Builder => Timesheet::query()->when($user !== null, fn (Builder $query) => $query->visibleTo($user));

        $first = CarbonImmutable::parse($visible()->min('work_date') ?? today())->startOfMonth();
        $last = CarbonImmutable::parse($visible()->max('work_date') ?? today())->startOfMonth();
        $thisMonth = CarbonImmutable::today()->startOfMonth();

        $first = $first->min($thisMonth);
        $last = $last->max($thisMonth);

        // Không liệt kê quá 5 năm, phòng một dòng nhập nhầm ngày.
        if ($first->diffInMonths($last) > 60) {
            $first = $last->subMonths(60);
        }

        $options = [];

        for ($month = $last; $month->gte($first); $month = $month->subMonth()) {
            $options[$month->format('Y-m')] = 'Tháng '.$month->format('m/Y');
        }

        return $options;
    }

    /**
     * @param  Builder<Timesheet>  $query
     * @return Builder<Timesheet>
     */
    public static function applyMonth(Builder $query, mixed $month): Builder
    {
        if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return $query;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return $query
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $start->endOfMonth()->toDateString());
    }

    /**
     * Công việc đã có dòng công (người dùng được xem), mới nhất ở trên.
     *
     * @return array<int, string>
     */
    public static function taskOptions(): array
    {
        $user = auth()->user();

        return Task::query()
            ->whereIn('id', Timesheet::query()
                ->when($user !== null, fn (Builder $query) => $query->visibleTo($user))
                ->whereNotNull('task_id')
                ->select('task_id'))
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'name', 'start_date'])
            ->mapWithKeys(fn (Task $task): array => [$task->getKey() => TaskField::label($task)])
            ->all();
    }
}
