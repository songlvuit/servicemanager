<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Expense;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Chi phí" của dự án: các đề nghị chi (chỉ xem; bấm vào dòng để mở và xử lý).
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Chi phí';

    protected static ?string $modelLabel = 'đề nghị chi';

    protected static ?string $pluralModelLabel = 'đề nghị chi';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('expenses.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('employee')->visibleTo(auth()->user()))
            ->columns([
                Columns::code(label: 'Mã'),
                TextColumn::make('description')
                    ->label('Nội dung chi')
                    ->description(fn (Expense $record): ?string => $record->employee?->name)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Nhóm')
                    ->badge(),
                TextColumn::make('expense_date')
                    ->label('Ngày')
                    ->date()
                    ->sortable(),
                Columns::money('amount', 'Trước thuế', summarize: true),
                Columns::money('total_amount', 'Tổng chi', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Expense $record): string => ExpenseResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createExpense')
                    ->label('Tạo đề nghị chi')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isClosed() && ! $this->getOwnerRecord()->trashed() && ExpenseResource::canCreate())
                    ->url(fn (): string => ExpenseResource::getUrl('create', ['project' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có đề nghị chi nào.')
            ->emptyStateDescription('Các khoản chi của dự án sẽ hiển thị tại đây.');
    }
}
