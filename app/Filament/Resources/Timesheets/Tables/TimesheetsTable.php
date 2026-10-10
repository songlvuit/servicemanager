<?php

namespace App\Filament\Resources\Timesheets\Tables;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bảng giờ công, dùng chung cho màn hình Chấm công và tab Chấm công của công việc.
 * Cột chi phí chỉ hiện với người có quyền xem chi phí nội bộ.
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
                ->visible(fn (): bool => Access::can('timesheets.view_all')),
            Filters::dateRange('work_date', 'Ngày làm việc'),
        ];

        if ($withTask) {
            array_unshift($filters, Filters::withoutTask());
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
            ->emptyStateDescription('Ghi nhận thời gian làm việc cho công việc; chi phí nhân sự = số giờ × đơn giá giờ công.');
    }
}
