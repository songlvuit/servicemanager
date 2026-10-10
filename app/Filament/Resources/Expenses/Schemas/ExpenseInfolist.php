<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Filament\Support\ApprovalTimeline;
use App\Filament\Support\Columns;
use App\Filament\Support\TaskField;
use App\Models\Expense;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Lý do từ chối')
                    ->description(fn (Expense $record): string => (string) $record->rejection_reason)
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Rejected && filled($record->rejection_reason)),

                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã đề nghị chi')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('employee.name')
                            ->label('Người đề nghị'),
                        TaskField::entry(),
                        TextEntry::make('category')
                            ->label('Nhóm chi phí')
                            ->badge(),
                        TextEntry::make('expense_date')
                            ->label('Ngày phát sinh')
                            ->date(),
                        TextEntry::make('description')
                            ->label('Nội dung chi')
                            ->columnSpanFull(),
                    ]),

                Section::make('Tài chính')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Columns::moneyEntry('amount', 'Số tiền trước thuế'),
                        Columns::moneyEntry('tax', 'Thuế GTGT'),
                        Columns::moneyEntry('total_amount', 'Tổng số tiền chi')
                            ->weight(FontWeight::SemiBold),
                    ]),

                Section::make('Nhà cung cấp & thanh toán')
                    ->icon(Heroicon::OutlinedTruck)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('vendor.name')
                            ->label('Nhà cung cấp')
                            ->placeholder('—'),
                        TextEntry::make('payment_method')
                            ->label('Hình thức thanh toán')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('paid_at')
                            ->label('Ngày thanh toán')
                            ->date()
                            ->placeholder('Chưa thanh toán'),
                        TextEntry::make('payer.name')
                            ->label('Người thanh toán')
                            ->placeholder('—'),
                        TextEntry::make('payment_reference')
                            ->label('Số chứng từ / tham chiếu')
                            ->placeholder('—'),
                        TextEntry::make('approver.name')
                            ->label('Người duyệt cuối')
                            ->placeholder('—'),
                    ]),

                ApprovalTimeline::section(),
            ]);
    }
}
