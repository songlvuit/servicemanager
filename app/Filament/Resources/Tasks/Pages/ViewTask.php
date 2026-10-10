<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang của một công việc: nơi quản lý tiền của "job" — ngân sách, đề nghị
 * chi, mua hàng, giờ công — và xem các lịch làm việc gắn với nó.
 */
class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Tổng quan';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedChartBarSquare;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $task = $this->getRecord();

        return $task->project !== null
            ? $task->project->code.' · '.$task->project->name
            : 'Việc độc lập';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createExpense')
                ->label('Tạo đề nghị chi')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Cancelled && ExpenseResource::canCreate())
                ->url(fn (Task $record): string => ExpenseResource::getUrl('create', ['task' => $record->getKey()])),
            Action::make('createPurchaseRequest')
                ->label('Tạo yêu cầu mua hàng')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('gray')
                ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Cancelled && PurchaseRequestResource::canCreate())
                ->url(fn (Task $record): string => PurchaseRequestResource::getUrl('create', ['task' => $record->getKey()])),
            EditAction::make()
                ->label('Sửa công việc'),
        ];
    }
}
