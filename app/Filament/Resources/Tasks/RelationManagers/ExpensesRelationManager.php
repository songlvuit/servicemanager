<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

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
 * Tab "Đề nghị chi" của công việc: các đề nghị chi gắn vào công việc ở mọi
 * trạng thái, kể cả nháp và đang duyệt (chỉ xem; bấm vào dòng để mở và xử lý).
 * Danh sách mọi chi phí của công việc nằm ở tab "Chi phí" (CostsRelationManager).
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Đề nghị chi';

    protected static ?string $modelLabel = 'đề nghị chi';

    protected static ?string $pluralModelLabel = 'đề nghị chi';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentCurrencyDollar;

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
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isCancelled() && ExpenseResource::canCreate())
                    ->url(fn (): string => ExpenseResource::getUrl('create', ['task' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có đề nghị chi nào.')
            ->emptyStateDescription('Tạo đề nghị chi và chọn công việc này để khoản chi được tính vào chi phí của công việc.');
    }
}
