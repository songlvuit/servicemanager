<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\TableExport;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Ghi nhận thanh toán cho một hóa đơn đang còn phải thu.
            Action::make('recordPayment')
                ->label('Ghi nhận thanh toán')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => auth()->user()?->can('create', Payment::class) ?? false)
                ->modalHeading('Ghi nhận thanh toán')
                ->modalDescription('Thanh toán đã ghi nhận không sửa và không xoá được — hãy kiểm tra kỹ hóa đơn và số tiền.')
                ->modalSubmitActionLabel('Ghi nhận')
                ->schema([
                    Select::make('invoice_id')
                        ->label('Hóa đơn')
                        ->options(fn (): array => $this->openInvoiceOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        // Chọn hóa đơn thì điền sẵn toàn bộ số còn phải thu.
                        ->afterStateUpdated(fn (mixed $state, Set $set): mixed => $set(
                            'amount',
                            Decimal::trimZeros(Invoice::query()->open()->find($state)?->remaining_amount),
                        ))
                        ->columnSpanFull(),
                    ...InvoiceActions::paymentFields(),
                ])
                ->action(function (array $data): void {
                    $invoice = Invoice::query()->find($data['invoice_id'] ?? null);

                    if ($invoice !== null && InvoiceActions::pay($invoice, $data)) {
                        $this->resetTable();
                    }
                }),
            TableExport::make('payments.export', 'thanh-toan', 'Danh sách thanh toán', fn (): array => [
                'Ngày thanh toán' => 'payment_date',
                'Hóa đơn' => 'invoice.code',
                'Khách hàng' => 'customer.name',
                'Số tiền' => 'amount',
                'Hình thức' => 'payment_method',
                'Số chứng từ' => 'reference',
                'Người ghi nhận' => 'creator.name',
                'Ghi chú' => 'notes',
            ]),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function openInvoiceOptions(): array
    {
        return Invoice::query()
            ->with('customer')
            ->open()
            ->orderBy('due_date')
            ->get()
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->getKey() => "{$invoice->code} — {$invoice->customer?->name} — còn ".Money::format($invoice->remaining_amount),
            ])
            ->all();
    }
}
