<?php

namespace App\Filament\Resources\BudgetItems\Tables;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Filament\Support\Columns;
use App\Models\BudgetItem;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * Bảng Ngân sách – Cam kết – Thực tế (mục 18–19), dùng chung cho màn hình
 * Ngân sách (gom theo dự án) và tab Ngân sách của dự án.
 */
class BudgetItemsTable
{
    public static function configure(Table $table, bool $withProject = true): Table
    {
        $table
            ->columns([
                TextColumn::make('project.name')
                    ->label('Dự án')
                    ->description(fn (BudgetItem $record): ?string => $record->project?->code)
                    ->searchable()
                    ->visible($withProject),
                TextColumn::make('category')
                    ->label('Nhóm chi phí')
                    ->badge()
                    ->description(fn (BudgetItem $record): ?string => $record->description)
                    ->sortable(),
                Columns::money('planned_amount', 'Ngân sách', summarize: true),
                Columns::money('committed_amount', 'Đã cam kết', summarize: true),
                Columns::money('actual_amount', 'Thực tế', summarize: true),
                Columns::money('variance', 'Còn lại', summarize: true)
                    ->color(fn (BudgetItem $record): ?string => (float) $record->variance < 0 ? 'danger' : null),
                TextColumn::make('cost_status')
                    ->label('Tình trạng')
                    ->state(fn (BudgetItem $record): CostStatus => $record->costStatus())
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Nhóm chi phí')
                    ->options(BudgetCategory::class)
                    ->multiple(),
            ])
            ->recordActions([
                // Ngân sách của dự án đã đóng bị khoá, kể cả với Super Admin.
                EditAction::make()
                    ->visible(fn (BudgetItem $record): bool => static::isOpen($record) && (auth()->user()?->can('update', $record) ?? false)),
                DeleteAction::make()
                    ->visible(fn (BudgetItem $record): bool => static::isOpen($record) && (auth()->user()?->can('delete', $record) ?? false)),
            ])
            ->defaultSort('category')
            ->emptyStateHeading('Chưa có ngân sách nào.')
            ->emptyStateDescription('Lập ngân sách theo từng nhóm chi phí để theo dõi Ngân sách – Cam kết – Thực tế.');

        if ($withProject) {
            $table
                ->groups([
                    Group::make('project.name')
                        ->label('Dự án')
                        ->collapsible(),
                ])
                ->defaultGroup('project.name');
        }

        return $table;
    }

    private static function isOpen(BudgetItem $record): bool
    {
        return ! ($record->project?->isClosed() ?? true);
    }
}
