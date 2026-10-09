<?php

namespace App\Filament\Resources\Contracts\Actions;

use App\Enums\ContractStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\WorkflowAction;
use App\Models\Contract;
use App\Services\Contracts\ContractWorkflow;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Các nút của quy trình hợp đồng (mục 15).
 */
class ContractActions
{
    public static function sendForSignature(): Action
    {
        return WorkflowAction::make('sendForSignature', 'Gửi ký', ContractStatus::PendingSignature)
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (Contract $record): string => "Gửi hợp đồng {$record->code} đi ký?")
            ->modalDescription('Hợp đồng chuyển sang "Chờ ký" và không sửa được nữa, trừ khi đưa về bản nháp.')
            ->action(fn (Contract $record) => WorkflowAction::run(
                $record,
                fn () => app(ContractWorkflow::class)->sendForSignature($record, auth()->user()),
                'Hợp đồng đã chuyển sang chờ ký.',
            ));
    }

    public static function activate(): Action
    {
        return WorkflowAction::make('activate', 'Xác nhận đã ký', ContractStatus::Active)
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading(fn (Contract $record): string => "Xác nhận hợp đồng {$record->code} đã ký?")
            ->modalDescription(fn (Contract $record): string => 'Bạn có chắc chắn hợp đồng trị giá '.Money::format($record->total_value).' đã được hai bên ký? Sau bước này mới tạo được dự án.')
            ->schema([
                DatePicker::make('sign_date')
                    ->label('Ngày ký')
                    ->default(fn (Contract $record): string => ($record->sign_date ?? today())->toDateString())
                    ->required()
                    ->maxDate(today())
                    ->native(false)
                    ->displayFormat('d/m/Y'),
            ])
            ->action(fn (Contract $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(ContractWorkflow::class)->activate($record, auth()->user(), Carbon::parse($data['sign_date'])),
                'Hợp đồng đã có hiệu lực.',
            ));
    }

    public static function returnToDraft(): Action
    {
        return WorkflowAction::make('returnToDraft', 'Đưa về nháp', ContractStatus::Draft)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(fn (Contract $record): string => "Đưa hợp đồng {$record->code} về bản nháp?")
            ->modalDescription('Dùng khi cần chỉnh sửa nội dung trước khi ký.')
            ->action(fn (Contract $record) => WorkflowAction::run(
                $record,
                fn () => app(ContractWorkflow::class)->returnToDraft($record, auth()->user()),
                'Hợp đồng đã trở về bản nháp.',
            ));
    }

    public static function complete(): Action
    {
        return WorkflowAction::make('complete', 'Hoàn thành hợp đồng', ContractStatus::Completed)
            ->icon(Heroicon::OutlinedFlag)
            ->color('info')
            ->modalHeading(fn (Contract $record): string => "Hoàn thành hợp đồng {$record->code}?")
            ->modalDescription('Chỉ thực hiện khi mọi dự án của hợp đồng đã kết thúc. Thao tác này không hoàn tác được.')
            ->action(fn (Contract $record) => WorkflowAction::run(
                $record,
                fn () => app(ContractWorkflow::class)->complete($record, auth()->user()),
                'Hợp đồng đã hoàn thành.',
            ));
    }

    public static function cancel(): Action
    {
        return WorkflowAction::make('cancel', 'Huỷ hợp đồng', ContractStatus::Cancelled)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(fn (Contract $record): string => "Huỷ hợp đồng {$record->code}?")
            ->modalDescription('Hợp đồng đã huỷ không dùng lại được. Thao tác này không hoàn tác được.')
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do huỷ')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Contract $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(ContractWorkflow::class)->cancel($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã huỷ hợp đồng.',
            ));
    }

    /**
     * Rule 1: chỉ hợp đồng đang hiệu lực mới tạo được dự án.
     */
    public static function createProject(): Action
    {
        return Action::make('createProject')
            ->label('Tạo dự án')
            ->icon(Heroicon::OutlinedBriefcase)
            ->color('gray')
            ->visible(fn (Contract $record): bool => ! $record->trashed() && $record->isActive() && ProjectResource::canCreate())
            ->url(fn (Contract $record): string => ProjectResource::getUrl('create', ['contract' => $record->getKey()]));
    }
}
