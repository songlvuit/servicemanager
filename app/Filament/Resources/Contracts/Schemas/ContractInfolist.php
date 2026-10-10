<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Enums\ContractStatus;
use App\Filament\Pages\Reports\ProfitReport;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Contract;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class ContractInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Hợp đồng sắp hết hạn')
                    ->description(fn (Contract $record): string => $record->daysUntilEnd() < 0
                        ? 'Hợp đồng đã quá ngày kết thúc '.abs($record->daysUntilEnd()).' ngày nhưng vẫn đang hiệu lực.'
                        : 'Còn '.$record->daysUntilEnd().' ngày tới ngày kết thúc hợp đồng.')
                    ->warning()
                    ->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->isExpiringSoon()),
                Callout::make('Lý do huỷ')
                    ->description(fn (Contract $record): string => (string) $record->cancel_reason)
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Cancelled && filled($record->cancel_reason)),

                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Số hợp đồng')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('customer.name')
                            ->label('Khách hàng')
                            ->url(fn (Contract $record): ?string => $record->customer !== null && CustomerResource::canView($record->customer)
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('title')
                            ->label('Tên hợp đồng')
                            ->columnSpan(2),
                        TextEntry::make('quotation.code')
                            ->label('Báo giá')
                            ->placeholder('—')
                            ->url(fn (Contract $record): ?string => $record->quotation !== null && QuotationResource::canView($record->quotation)
                                ? QuotationResource::getUrl('view', ['record' => $record->quotation])
                                : null),
                    ]),

                Section::make('Giá trị')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Columns::moneyEntry('contract_value', 'Giá trị trước thuế'),
                        Columns::moneyEntry('tax_amount', 'Thuế GTGT'),
                        Columns::moneyEntry('total_value', 'Tổng giá trị (gồm thuế)')
                            ->weight(FontWeight::SemiBold),
                    ]),

                Section::make('Chi phí & lợi nhuận')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->description('Chi phí là tổng chi phí (trước thuế) của các công việc gắn với hợp đồng — xem tab Công việc.')
                    ->columns(['default' => 2, 'lg' => 4])
                    ->columnSpanFull()
                    ->visible(fn (): bool => Access::canSeeCosts())
                    ->schema([
                        Columns::moneyEntry('budget', 'Ngân sách các công việc'),
                        Columns::moneyEntry('committed_cost', 'Chi phí đã cam kết'),
                        Columns::moneyEntry('actual_cost', 'Chi phí thực tế')
                            ->weight(FontWeight::SemiBold),
                        Columns::moneyEntry('actual_profit', 'Lợi nhuận')
                            ->weight(FontWeight::SemiBold)
                            ->color(fn (Contract $record): string => (float) $record->actual_profit < 0 ? 'danger' : 'success')
                            ->helperText('Giá trị hợp đồng − chi phí thực tế.'),
                        TextEntry::make('margin_band')
                            ->label('Biên lợi nhuận')
                            // Chưa phát sinh chi phí thì chưa đánh giá (không coi 100% là "tốt").
                            ->state(fn (Contract $record): ?string => ($band = ProfitReport::band($record)) === null
                                ? null
                                : Money::percent($record->profit_margin).' · '.$band->getLabel())
                            ->badge()
                            ->color(fn (Contract $record): string => ProfitReport::band($record)?->getColor() ?? 'gray')
                            ->placeholder('Chưa có chi phí'),
                        Columns::moneyEntry('invoiced_amount', 'Đã xuất hóa đơn (gồm thuế)'),
                        Columns::moneyEntry('collected_amount', 'Đã thu'),
                        TextEntry::make('receivable')
                            ->label('Còn phải thu')
                            ->state(fn (Contract $record): string => Money::format(Decimal::sub($record->invoiced_amount, $record->collected_amount))),
                    ]),

                Section::make('Thời hạn & điều khoản')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('sign_date')
                            ->label('Ngày ký')
                            ->date()
                            ->placeholder('Chưa ký'),
                        TextEntry::make('start_date')
                            ->label('Ngày bắt đầu')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('end_date')
                            ->label('Ngày kết thúc')
                            ->date()
                            ->placeholder('—')
                            ->color(fn (Contract $record): ?string => $record->isExpiringSoon() ? 'warning' : null),
                        TextEntry::make('payment_terms')
                            ->label('Điều khoản thanh toán')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Hệ thống')
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label('Người tạo')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Ngày tạo')
                            ->dateTime(),
                        TextEntry::make('activator.name')
                            ->label('Người xác nhận hiệu lực')
                            ->placeholder('—'),
                        TextEntry::make('activated_at')
                            ->label('Ngày có hiệu lực')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
