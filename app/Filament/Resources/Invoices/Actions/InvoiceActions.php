<?php

namespace App\Filament\Resources\Invoices\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Fields;
use App\Filament\Support\WorkflowAction;
use App\Models\Invoice;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\PaymentService;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Component as Livewire;

/**
 * Các nút của hóa đơn: phát hành, ghi nhận thanh toán, huỷ (mục 30–31).
 */
class InvoiceActions
{
    public static function issue(): Action
    {
        return WorkflowAction::make('issue', 'Phát hành', InvoiceStatus::Issued, [InvoiceStatus::Draft])
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (Invoice $record): string => "Phát hành hóa đơn {$record->code}?")
            ->modalDescription(fn (Invoice $record): string => 'Bạn có chắc chắn muốn phát hành hóa đơn '.Money::format($record->total).'? Hóa đơn đã phát hành không sửa và không xoá được.')
            ->action(fn (Invoice $record) => WorkflowAction::run(
                $record,
                fn () => app(InvoiceWorkflow::class)->issue($record, auth()->user()),
                'Đã phát hành hóa đơn.',
            ));
    }

    /**
     * Ghi nhận một lần khách thanh toán. Mặc định là toàn bộ số còn phải thu;
     * thu đủ thì hóa đơn tự chuyển "Đã thu đủ" (Rule 3).
     */
    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Ghi nhận thanh toán')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('success')
            ->visible(fn (Invoice $record): bool => ! $record->trashed()
                && $record->status->isOpen()
                && (auth()->user()?->can('recordPayment', $record) ?? false))
            ->modalHeading(fn (Invoice $record): string => "Ghi nhận thanh toán cho hóa đơn {$record->code}")
            ->modalDescription(fn (Invoice $record): string => 'Còn phải thu '.Money::format($record->remaining_amount).'. Thanh toán đã ghi nhận không sửa và không xoá được — hãy kiểm tra kỹ số tiền.')
            ->modalSubmitActionLabel('Ghi nhận')
            ->schema(static::paymentFields())
            ->action(function (Invoice $record, array $data, Livewire $livewire): void {
                // Tải lại trang để tab "Thanh toán" và số liệu hóa đơn hiển thị ngay khoản vừa thu.
                if (static::pay($record, $data)) {
                    $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $record]));
                }
            });
    }

    public static function cancel(): Action
    {
        return WorkflowAction::make('cancel', 'Huỷ hóa đơn', InvoiceStatus::Cancelled)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(fn (Invoice $record): string => "Huỷ hóa đơn {$record->code}?")
            ->modalDescription('Chỉ huỷ được hóa đơn chưa có thanh toán. Thao tác này không hoàn tác được.')
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do huỷ')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Invoice $record, array $data) => WorkflowAction::run(
                $record,
                fn () => app(InvoiceWorkflow::class)->cancel($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã huỷ hóa đơn.',
            ));
    }

    /**
     * Các ô của form ghi nhận thanh toán (dùng lại ở màn hình Thanh toán).
     *
     * @return list<Component>
     */
    public static function paymentFields(): array
    {
        return [
            Fields::money('amount')
                ->label('Số tiền thu')
                ->default(fn (?Invoice $record): ?string => $record === null ? null : Decimal::trimZeros($record->remaining_amount))
                ->required()
                ->minValue(1),
            DatePicker::make('payment_date')
                ->label('Ngày thanh toán')
                ->default(fn (): string => today()->toDateString())
                ->required()
                ->maxDate(today())
                ->native(false)
                ->displayFormat('d/m/Y'),
            Select::make('payment_method')
                ->label('Hình thức thanh toán')
                ->options(PaymentMethod::class)
                ->default(PaymentMethod::BankTransfer->value)
                ->required(),
            TextInput::make('reference')
                ->label('Số chứng từ / mã giao dịch')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Ghi chú')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function pay(Invoice $invoice, array $data): bool
    {
        $method = $data['payment_method'] instanceof PaymentMethod
            ? $data['payment_method']
            : PaymentMethod::from((string) $data['payment_method']);

        try {
            $payment = app(PaymentService::class)->record(
                $invoice,
                auth()->user(),
                (string) $data['amount'],
                $method,
                Carbon::parse($data['payment_date']),
                $data['reference'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (WorkflowException|AuthorizationException $exception) {
            Notification::make()
                ->title('Không ghi nhận được thanh toán')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        $invoice->refresh();

        Notification::make()
            ->title('Đã ghi nhận thanh toán '.Money::format($payment->amount).'.')
            ->body($invoice->status === InvoiceStatus::Paid
                ? "Hóa đơn {$invoice->code} đã thu đủ."
                : "Hóa đơn {$invoice->code} còn phải thu ".Money::format($invoice->remaining_amount).'.')
            ->success()
            ->send();

        return true;
    }
}
