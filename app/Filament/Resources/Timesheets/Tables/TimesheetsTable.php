<?php

namespace App\Filament\Resources\Timesheets\Tables;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Timesheet;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bảng giờ công, dùng chung cho màn hình Chấm công và tab Chấm công của dự án.
 * Cột chi phí chỉ hiện với người có quyền xem chi phí nội bộ.
 */
class TimesheetsTable
{
    public static function configure(Table $table, bool $withProject = true): Table
    {
        $filters = [
            SelectFilter::make('user_id')
                ->label('Nhân viên')
                ->options(fn (): array => TaskForm::userOptions())
                ->searchable()
                ->visible(fn (): bool => Access::can('timesheets.view_all')),
            Filters::dateRange('work_date', 'Ngày làm việc'),
        ];

        if ($withProject) {
            array_unshift($filters, SelectFilter::make('project_id')
                ->label('Dự án')
                ->relationship('project', 'name')
                ->searchable()
                ->preload());
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
                TextColumn::make('project.name')
                    ->label('Dự án')
                    ->description(fn (Timesheet $record): ?string => $record->task?->name)
                    ->wrap()
                    ->visible($withProject),
                TextColumn::make('task.name')
                    ->label('Công việc')
                    ->placeholder('—')
                    ->wrap()
                    ->visible(! $withProject),
                TextColumn::make('description')
                    ->label('Nội dung')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('hours')
                    ->label('Số giờ')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label('Tổng giờ')->numeric(decimalPlaces: 2)),
                Columns::money('cost', 'Chi phí nhân sự', summarize: true)
                    ->visible(fn (): bool => Access::canSeeCosts()),
            ])
            ->filters($filters)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('work_date', 'desc')
            ->emptyStateHeading('Chưa có giờ công nào.')
            ->emptyStateDescription('Ghi nhận thời gian làm việc cho dự án; chi phí nhân sự = số giờ × đơn giá giờ công.');
    }
}
