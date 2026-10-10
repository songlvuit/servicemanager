<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Enums\TaskStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\RelationManagers\ExpensesRelationManager as ProjectExpenses;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tab "Chi phí" của công việc: các đề nghị chi gắn vào công việc này.
 */
class ExpensesRelationManager extends ProjectExpenses
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->headerActions([
                Action::make('createExpense')
                    ->label('Tạo đề nghị chi')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->getOwnerRecord()->status !== TaskStatus::Cancelled && ExpenseResource::canCreate())
                    ->url(fn (): string => ExpenseResource::getUrl('create', ['task' => $this->getOwnerRecord()->getKey()])),
            ])
            ->emptyStateDescription('Tạo đề nghị chi và chọn công việc này để khoản chi được tính vào chi phí của công việc.');
    }
}
