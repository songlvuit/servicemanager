<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Columns;
use App\Models\Invoice;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Hóa đơn quá hạn')
                    ->description(fn (Invoice $record): string => 'Đã quá hạn thanh toán '.$record->daysOverdue().' ngày, còn phải thu '.Money::format($record->remaining_amount).'.')
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $record->isPastDue()),
                Callout::make('Lý do huỷ')
                    ->description(fn (Invoice $record): string => (string) $record->cancel_reason)
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Cancelled && filled($record->cancel_reason)),

                Section::make('Thanh toán')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        Columns::moneyEntry('subtotal', 'Giá trị trước thuế'),
                        TextEntry::make('tax')
                            ->label('Thuế GTGT')
                            ->formatStateUsing(fn (mixed $state, Invoice $record): string => Money::format($state).' ('.Money::percent($record->tax_rate).')'),
                        Columns::moneyEntry('total', 'Tổng thanh toán')
                            ->weight(FontWeight::Bold),
                        Columns::moneyEntry('paid_amount', 'Đã thu')
                            ->color('success'),
                        Columns::moneyEntry('remaining_amount', 'Còn phải thu')
                            ->weight(FontWeight::Bold)
                            ->color(fn (Invoice $record): string => Decimal::isPositive($record->remaining_amount) ? 'danger' : 'success'),
                    ]),

                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Số hóa đơn')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('customer.name')
                            ->label('Khách hàng')
                            ->url(fn (Invoice $record): ?string => $record->customer !== null && CustomerResource::canView($record->customer)
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('contract.code')
                            ->label('Hợp đồng')
                            ->url(fn (Invoice $record): ?string => $record->contract !== null && ContractResource::canView($record->contract)
                                ? ContractResource::getUrl('view', ['record' => $record->contract])
                                : null),
                        TextEntry::make('acceptance.code')
                            ->label('Biên bản nghiệm thu')
                            ->placeholder('—')
                            ->url(fn (Invoice $record): ?string => $record->acceptance !== null && AcceptanceResource::canView($record->acceptance)
                                ? AcceptanceResource::getUrl('view', ['record' => $record->acceptance])
                                : null),
                        TextEntry::make('invoice_date')
                            ->label('Ngày hóa đơn')
                            ->date(),
                        TextEntry::make('due_date')
                            ->label('Hạn thanh toán')
                            ->date()
                            ->color(fn (Invoice $record): ?string => $record->isPastDue() ? 'danger' : null),
                        TextEntry::make('creator.name')
                            ->label('Người lập')
                            ->placeholder('—'),
                        TextEntry::make('description')
                            ->label('Nội dung hóa đơn')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
