<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\BudgetCategory;
use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Expense;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Mã'),
                TextColumn::make('description')
                    ->label('Nội dung chi')
                    ->description(fn (Expense $record): ?string => implode(' · ', array_filter([$record->project?->name, $record->task?->name])) ?: null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('employee.name')
                    ->label('Người đề nghị')
                    ->toggleable(),
                TextColumn::make('category')
                    ->label('Nhóm')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('expense_date')
                    ->label('Ngày')
                    ->date()
                    ->sortable(),
                Columns::money('total_amount', 'Số tiền', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ExpenseStatus::class)
                    ->multiple(),
                SelectFilter::make('category')
                    ->label('Nhóm chi phí')
                    ->options(BudgetCategory::class)
                    ->multiple(),
                SelectFilter::make('project_id')
                    ->label('Dự án')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
                Filters::withoutProject(),
                SelectFilter::make('vendor_id')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => ExpenseForm::supplierOptions())
                    ->searchable(),
                Filters::dateRange('expense_date', 'Ngày phát sinh'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Expense $record): bool => ExpenseResource::canEdit($record)),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có đề nghị chi nào.')
            ->emptyStateDescription('Tạo đề nghị chi cho một dự án; đề nghị sẽ đi qua các cấp duyệt trước khi thanh toán.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo đề nghị chi'),
            ]);
    }
}
