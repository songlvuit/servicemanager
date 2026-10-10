<?php

namespace App\Filament\Resources\Expenses\Actions;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Filament\Support\WorkflowAction;
use App\Models\Expense;
use App\Services\Expenses\ExpenseWorkflow;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Các nút của quy trình đề nghị chi (mục 20–21). Nút chỉ hiện khi trạng thái
 * cho phép và người dùng có quyền; mọi thao tác đều hỏi xác nhận (mục 66).
 */
class ExpenseActions
{
    public static function submit(): Action
    {
        return WorkflowAction::make('submit', 'Gửi duyệt', ExpenseStatus::Submitted, [ExpenseStatus::Draft])
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (Expense $record): string => "Gửi duyệt đề nghị chi {$record->code}?")
            ->modalDescription(fn (Expense $record): string => 'Đề nghị chi '.Money::format($record->total_amount).' sẽ đi qua các cấp duyệt theo quy trình và không sửa được trong lúc chờ duyệt.')
            ->action(fn (Expense $record) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->submit($record, auth()->user()),
                'Đã gửi duyệt đề nghị chi.',
            ));
    }

    public static function approve(): Action
    {
        return WorkflowAction::make('approve', 'Duyệt', ExpenseStatus::Approved)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Expense $record): string => "Duyệt đề nghị chi {$record->code}?")
            ->modalDescription(fn (Expense $record): string => 'Bạn có chắc chắn muốn duyệt khoản chi '.Money::format($record->total_amount).'?')
            ->schema([
                Textarea::make('comment')
                    ->label('Ghi chú (không bắt buộc)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(fn (Expense $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->approve($record, auth()->user(), $data['comment'] ?? null),
                'Đã duyệt bước của bạn.',
            ));
    }

    public static function reject(): Action
    {
        return WorkflowAction::make('reject', 'Từ chối', ExpenseStatus::Rejected)
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (Expense $record): string => "Từ chối đề nghị chi {$record->code}?")
            ->modalDescription('Người đề nghị sẽ nhận được lý do và có thể soạn lại để gửi duyệt lần nữa.')
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do từ chối')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Expense $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->reject($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã từ chối đề nghị chi.',
            ));
    }

    public static function revise(): Action
    {
        return WorkflowAction::make('revise', 'Soạn lại', ExpenseStatus::Draft)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(fn (Expense $record): string => "Soạn lại đề nghị chi {$record->code}?")
            ->modalDescription('Đề nghị chi trở về trạng thái Nháp để chỉnh sửa và gửi duyệt lại.')
            ->action(fn (Expense $record) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->revise($record, auth()->user()),
                'Đề nghị chi đã trở về bản nháp.',
            ));
    }

    /**
     * Rule 2: chỉ khoản chi đã duyệt mới có nút thanh toán.
     */
    public static function pay(): Action
    {
        return WorkflowAction::make('pay', 'Thanh toán', ExpenseStatus::Paid)
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('success')
            ->modalHeading(fn (Expense $record): string => "Thanh toán đề nghị chi {$record->code}?")
            ->modalDescription(fn (Expense $record): string => 'Bạn có chắc chắn đã chi '.Money::format($record->total_amount).'? Khoản này sẽ được ghi vào chi phí thực tế của công việc.')
            ->schema([
                Select::make('payment_method')
                    ->label('Hình thức thanh toán')
                    ->options(PaymentMethod::class)
                    ->default(fn (Expense $record): string => ($record->payment_method ?? PaymentMethod::BankTransfer)->value)
                    ->required(),
                DatePicker::make('paid_at')
                    ->label('Ngày thanh toán')
                    ->default(fn (): string => today()->toDateString())
                    ->required()
                    ->maxDate(today())
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                TextInput::make('reference')
                    ->label('Số chứng từ / mã giao dịch')
                    ->maxLength(255),
            ])
            ->action(fn (Expense $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->pay(
                    $record,
                    auth()->user(),
                    PaymentMethod::from($data['payment_method'] instanceof PaymentMethod ? $data['payment_method']->value : (string) $data['payment_method']),
                    Carbon::parse($data['paid_at']),
                    $data['reference'] ?? null,
                ),
                'Đã ghi nhận thanh toán.',
            ));
    }

    public static function complete(): Action
    {
        return WorkflowAction::make('complete', 'Hoàn tất hồ sơ', ExpenseStatus::Completed)
            ->icon(Heroicon::OutlinedFlag)
            ->color('info')
            ->modalHeading(fn (Expense $record): string => "Hoàn tất hồ sơ {$record->code}?")
            ->modalDescription('Xác nhận chứng từ đã đầy đủ. Sau bước này không thêm / xoá được tài liệu của đề nghị chi.')
            ->action(fn (Expense $record) => WorkflowAction::run(
                $record,
                fn () => app(ExpenseWorkflow::class)->complete($record, auth()->user()),
                'Đề nghị chi đã hoàn tất.',
            ));
    }
}
