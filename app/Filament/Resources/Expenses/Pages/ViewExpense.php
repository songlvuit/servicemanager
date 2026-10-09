<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\Actions\ExpenseActions;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang chi tiết đề nghị chi: thông tin, quy trình duyệt, chứng từ và các nút thao tác.
 */
class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Đề nghị chi';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedBanknotes;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Đề nghị chi '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->description;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Expense $record): bool => ExpenseResource::canEdit($record)),
            ExpenseActions::submit(),
            ExpenseActions::approve(),
            ExpenseActions::reject(),
            ExpenseActions::revise(),
            ExpenseActions::pay(),
            ExpenseActions::complete(),
            ActionGroup::make([
                DeleteAction::make()
                    ->visible(fn (Expense $record): bool => ExpenseResource::canDelete($record)),
                RestoreAction::make(),
            ]),
        ];
    }
}
