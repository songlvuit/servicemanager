<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\ContractLink;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
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

        return implode(' · ', array_filter([
            $task->customer?->name,
            $task->parent !== null ? 'thuộc: '.$task->parent->name : null,
        ])) ?: null;
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
            $this->linkContractAction(),
            EditAction::make()
                ->label('Sửa công việc')
                ->mutateRecordDataUsing(fn (array $data): array => TaskForm::state($data)),
        ];
    }

    /**
     * Gắn công việc vào hợp đồng / báo giá. Dành cho người được xem hợp đồng,
     * báo giá (mặc định Giám đốc, Kế toán) — kể cả khi họ không có quyền sửa
     * công việc, vì đây là thông tin của phía hợp đồng.
     */
    public function linkContractAction(): Action
    {
        return Action::make('linkContract')
            ->label('Hợp đồng & báo giá')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('gray')
            ->visible(fn (): bool => ContractLink::canSee())
            ->modalHeading('Gắn hợp đồng & báo giá')
            ->modalDescription('Chi phí của công việc được tính vào hợp đồng đã gắn. Công việc con tự theo công việc cha.')
            ->modalSubmitActionLabel('Lưu')
            ->modalWidth(Width::Large)
            ->fillForm(fn (Task $record): array => array_filter([
                'contract_id' => ContractLink::canSeeContracts() ? $record->contract_id : null,
                'quotation_id' => ContractLink::canSeeQuotations() ? $record->quotation_id : null,
            ]))
            ->schema(fn (): array => ContractLink::fields())
            ->action(function (array $data, Task $record): void {
                abort_unless(ContractLink::canSee(), 403);

                if ($record->parent_id !== null) {
                    Notification::make()
                        ->title('Đây là công việc con: hãy gắn hợp đồng ở công việc cha.')
                        ->warning()
                        ->send();

                    return;
                }

                // Chỉ nhận đúng những ô người này được xem.
                $changes = array_intersect_key($data, array_flip(array_filter([
                    ContractLink::canSeeContracts() ? 'contract_id' : null,
                    ContractLink::canSeeQuotations() ? 'quotation_id' : null,
                ])));

                $record->linkToContract($changes);

                Notification::make()->title('Đã lưu hợp đồng & báo giá của công việc.')->success()->send();
            });
    }
}
