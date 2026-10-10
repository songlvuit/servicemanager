<?php

namespace App\Filament\Resources\PurchaseRequests\Actions;

use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\WorkflowAction;
use App\Models\PurchaseRequest;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Các nút của quy trình yêu cầu mua hàng (mục 24).
 */
class PurchaseRequestActions
{
    public static function submit(): Action
    {
        return WorkflowAction::make('submit', 'Gửi duyệt', PurchaseRequestStatus::Submitted, [PurchaseRequestStatus::Draft])
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (PurchaseRequest $record): string => "Gửi duyệt yêu cầu {$record->code}?")
            ->modalDescription(fn (PurchaseRequest $record): string => 'Yêu cầu mua hàng trị giá ước tính '.Money::format($record->amount).' sẽ được chuyển cho người duyệt.')
            ->action(fn (PurchaseRequest $record) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseRequestWorkflow::class)->submit($record, auth()->user()),
                'Đã gửi duyệt yêu cầu mua hàng.',
            ));
    }

    public static function approve(): Action
    {
        return WorkflowAction::make('approve', 'Duyệt', PurchaseRequestStatus::Approved, [PurchaseRequestStatus::Submitted])
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (PurchaseRequest $record): string => "Duyệt yêu cầu {$record->code}?")
            ->modalDescription(fn (PurchaseRequest $record): string => 'Bạn có chắc chắn muốn duyệt yêu cầu mua hàng '.Money::format($record->amount).'? Giá trị này sẽ được tính vào chi phí cam kết của công việc.')
            ->schema([
                Textarea::make('comment')
                    ->label('Ghi chú (không bắt buộc)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(fn (PurchaseRequest $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseRequestWorkflow::class)->approve($record, auth()->user(), $data['comment'] ?? null),
                'Đã duyệt bước của bạn.',
            ));
    }

    public static function reject(): Action
    {
        return WorkflowAction::make('reject', 'Từ chối', PurchaseRequestStatus::Rejected, [PurchaseRequestStatus::Submitted])
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (PurchaseRequest $record): string => "Từ chối yêu cầu {$record->code}?")
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do từ chối')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (PurchaseRequest $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseRequestWorkflow::class)->reject($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã từ chối yêu cầu mua hàng.',
            ));
    }

    public static function revise(): Action
    {
        return WorkflowAction::make('revise', 'Soạn lại', PurchaseRequestStatus::Draft)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(fn (PurchaseRequest $record): string => "Soạn lại yêu cầu {$record->code}?")
            ->modalDescription('Yêu cầu trở về trạng thái Nháp để chỉnh sửa và gửi duyệt lại.')
            ->action(fn (PurchaseRequest $record) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseRequestWorkflow::class)->revise($record, auth()->user()),
                'Yêu cầu đã trở về bản nháp.',
            ));
    }

    public static function cancel(): Action
    {
        return WorkflowAction::make('cancel', 'Huỷ yêu cầu', PurchaseRequestStatus::Cancelled)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(fn (PurchaseRequest $record): string => "Huỷ yêu cầu {$record->code}?")
            ->modalDescription('Yêu cầu đã huỷ không dùng lại được. Thao tác này không hoàn tác được.')
            ->action(fn (PurchaseRequest $record) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseRequestWorkflow::class)->cancel($record, auth()->user()),
                'Đã huỷ yêu cầu mua hàng.',
            ));
    }

    /**
     * Yêu cầu đã duyệt → lập đơn mua hàng gửi nhà cung cấp.
     */
    public static function createOrder(): Action
    {
        return Action::make('createOrder')
            ->label('Lập đơn mua hàng')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('success')
            ->visible(fn (PurchaseRequest $record): bool => $record->status === PurchaseRequestStatus::Approved && PurchaseOrderResource::canCreate())
            ->url(fn (PurchaseRequest $record): string => PurchaseOrderResource::getUrl('create', ['request' => $record->getKey()]));
    }
}
