<?php

namespace App\Filament\Resources\Acceptances\Actions;

use App\Enums\AcceptanceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\WorkflowAction;
use App\Models\Acceptance;
use App\Services\Billing\AcceptanceWorkflow;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Các nút của quy trình nghiệm thu (mục 29).
 */
class AcceptanceActions
{
    public static function submit(): Action
    {
        return WorkflowAction::make('submit', 'Gửi duyệt', AcceptanceStatus::Submitted)
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (Acceptance $record): string => "Gửi duyệt biên bản {$record->code}?")
            ->modalDescription('Biên bản không sửa được trong lúc chờ duyệt.')
            ->action(fn (Acceptance $record) => WorkflowAction::run(
                $record,
                fn () => app(AcceptanceWorkflow::class)->submit($record, auth()->user()),
                'Đã gửi duyệt biên bản nghiệm thu.',
            ));
    }

    public static function approve(): Action
    {
        return WorkflowAction::make('approve', 'Duyệt nghiệm thu', AcceptanceStatus::Approved)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Acceptance $record): string => "Duyệt biên bản {$record->code}?")
            ->modalDescription(fn (Acceptance $record): string => 'Bạn có chắc chắn muốn duyệt nghiệm thu trị giá '.Money::format($record->amount).'? Sau khi duyệt có thể lập hóa đơn từ biên bản này.')
            ->action(fn (Acceptance $record) => WorkflowAction::run(
                $record,
                fn () => app(AcceptanceWorkflow::class)->approve($record, auth()->user()),
                'Đã duyệt nghiệm thu.',
            ));
    }

    public static function reject(): Action
    {
        return WorkflowAction::make('reject', 'Từ chối', AcceptanceStatus::Rejected)
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (Acceptance $record): string => "Từ chối biên bản {$record->code}?")
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do từ chối')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Acceptance $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(AcceptanceWorkflow::class)->reject($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã từ chối biên bản nghiệm thu.',
            ));
    }

    public static function revise(): Action
    {
        return WorkflowAction::make('revise', 'Soạn lại', AcceptanceStatus::Draft)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(fn (Acceptance $record): string => "Soạn lại biên bản {$record->code}?")
            ->modalDescription('Biên bản trở về trạng thái Nháp để chỉnh sửa và gửi duyệt lại.')
            ->action(fn (Acceptance $record) => WorkflowAction::run(
                $record,
                fn () => app(AcceptanceWorkflow::class)->revise($record, auth()->user()),
                'Biên bản đã trở về bản nháp.',
            ));
    }

    /**
     * Đã nghiệm thu → lập hóa đơn cho khách hàng.
     */
    public static function createInvoice(): Action
    {
        return Action::make('createInvoice')
            ->label('Lập hóa đơn')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('success')
            ->visible(fn (Acceptance $record): bool => $record->status === AcceptanceStatus::Approved && InvoiceResource::canCreate())
            ->url(fn (Acceptance $record): string => InvoiceResource::getUrl('create', ['acceptance' => $record->getKey()]));
    }
}
