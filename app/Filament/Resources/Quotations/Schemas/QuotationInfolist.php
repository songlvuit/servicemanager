<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Access;
use App\Models\Quotation;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $canSeeCosts = Access::canSeeCosts();

        return $schema
            ->components([
                Callout::make('Biên lợi nhuận thấp.')
                    ->description(fn (Quotation $record): string => 'Biên lợi nhuận ước tính '
                        .($canSeeCosts ? Money::percent($record->estimated_margin).' ' : '')
                        .'thấp hơn mức '.Money::percent(config('quotation.low_margin_threshold')).'.')
                    ->warning()
                    ->visible(fn (Quotation $record): bool => Decimal::isPositive($record->subtotal) && $record->hasLowMargin())
                    ->columnSpanFull(),

                Callout::make('Cần Giám đốc duyệt.')
                    ->description(fn (Quotation $record): string => 'Chiết khấu '.Money::percent($record->discountPercent())
                        .' vượt mức '.Money::percent(config('quotation.director_discount_threshold')).' nên báo giá này phải do Giám đốc duyệt.')
                    ->warning()
                    ->visible(fn (Quotation $record): bool => in_array($record->status, [QuotationStatus::Draft, QuotationStatus::PendingApproval], true)
                        && $record->exceedsDirectorDiscountThreshold())
                    ->columnSpanFull(),

                Callout::make('Lý do')
                    ->description(fn (Quotation $record): string => (string) $record->rejection_reason)
                    ->danger()
                    ->visible(fn (Quotation $record): bool => filled($record->rejection_reason)
                        && in_array($record->status, [QuotationStatus::Rejected, QuotationStatus::Cancelled], true))
                    ->columnSpanFull(),

                static::generalSection(),
                static::itemsSection($canSeeCosts),
                static::totalsSection($canSeeCosts),
                static::approvalSection(),
            ]);
    }

    private static function generalSection(): Section
    {
        return Section::make('Thông tin chung')
            ->icon(Heroicon::OutlinedDocumentText)
            ->columns(4)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('code')
                    ->label('Số báo giá')
                    ->weight(FontWeight::SemiBold)
                    ->copyable(),
                TextEntry::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextEntry::make('quotation_date')
                    ->label('Ngày báo giá')
                    ->date(),
                TextEntry::make('valid_until')
                    ->label('Hiệu lực đến')
                    ->date()
                    ->color(fn (Quotation $record): ?string => $record->isPastValidity() ? 'danger' : null),
                TextEntry::make('customer.name')
                    ->label('Khách hàng')
                    ->url(fn (Quotation $record): ?string => $record->customer !== null && CustomerResource::canView($record->customer)
                        ? CustomerResource::getUrl('view', ['record' => $record->customer])
                        : null)
                    ->columnSpan(2),
                TextEntry::make('opportunity.title')
                    ->label('Cơ hội liên quan')
                    ->placeholder('—'),
                TextEntry::make('creator.name')
                    ->label('Người lập')
                    ->placeholder('—'),
                TextEntry::make('title')
                    ->label('Tiêu đề')
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->label('Mô tả / điều khoản')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    private static function itemsSection(bool $canSeeCosts): Section
    {
        $money = fn (mixed $state): string => Money::format($state);

        $columns = [
            TableColumn::make('Mô tả'),
            TableColumn::make('SL'),
            TableColumn::make('ĐVT'),
            TableColumn::make('Đơn giá'),
            TableColumn::make('Chiết khấu'),
            TableColumn::make('VAT'),
        ];

        $entries = [
            TextEntry::make('description')
                ->label('Mô tả'),
            TextEntry::make('quantity')
                ->label('Số lượng')
                ->formatStateUsing(fn (mixed $state): string => (string) Decimal::trimZeros((string) $state)),
            TextEntry::make('unit')
                ->label('Đơn vị tính')
                ->placeholder('—'),
            TextEntry::make('unit_price')
                ->label('Đơn giá')
                ->formatStateUsing($money),
            TextEntry::make('discount')
                ->label('Chiết khấu')
                ->formatStateUsing($money),
            TextEntry::make('tax_rate')
                ->label('Thuế VAT')
                ->formatStateUsing(fn (mixed $state): string => Money::percent((string) $state)),
        ];

        if ($canSeeCosts) {
            $columns[] = TableColumn::make('Giá vốn / ĐV');
            $entries[] = TextEntry::make('estimated_cost')
                ->label('Giá vốn / đơn vị')
                ->formatStateUsing($money);
        }

        $columns[] = TableColumn::make('Thành tiền');
        $entries[] = TextEntry::make('subtotal')
            ->label('Thành tiền')
            ->weight(FontWeight::SemiBold)
            ->formatStateUsing($money);

        return Section::make('Hạng mục báo giá')
            ->icon(Heroicon::OutlinedListBullet)
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('items')
                    ->label('Hạng mục')
                    ->hiddenLabel()
                    ->table($columns)
                    ->schema($entries),
            ]);
    }

    private static function totalsSection(bool $canSeeCosts): Section
    {
        $money = fn (mixed $state): string => Money::format($state);

        $entries = [
            TextEntry::make('gross_amount')
                ->label('Tổng trước chiết khấu')
                ->state(fn (Quotation $record): string => Money::format($record->grossAmount())),
            TextEntry::make('discount')
                ->label('Chiết khấu')
                ->formatStateUsing(fn (mixed $state, Quotation $record): string => Money::format($state)
                    .' ('.Money::percent($record->discountPercent()).')'),
            TextEntry::make('subtotal')
                ->label('Thành tiền trước thuế')
                ->formatStateUsing($money),
            TextEntry::make('tax')
                ->label('Thuế VAT')
                ->formatStateUsing($money),
            TextEntry::make('total')
                ->label('Tổng cộng')
                ->weight(FontWeight::Bold)
                ->size(TextSize::Large)
                ->color('primary')
                ->formatStateUsing($money),
        ];

        if ($canSeeCosts) {
            $entries[] = TextEntry::make('estimated_cost')
                ->label('Giá vốn ước tính')
                ->formatStateUsing($money);
            $entries[] = TextEntry::make('estimated_profit')
                ->label('Lợi nhuận ước tính')
                ->formatStateUsing($money);
            $entries[] = TextEntry::make('estimated_margin')
                ->label('Biên lợi nhuận')
                ->weight(FontWeight::Bold)
                ->color(fn (Quotation $record): string => $record->hasLowMargin() ? 'danger' : 'success')
                ->formatStateUsing(fn (mixed $state): string => Money::percent((string) $state));
        }

        return Section::make('Tổng hợp')
            ->icon(Heroicon::OutlinedCalculator)
            ->columns(4)
            ->columnSpanFull()
            ->schema($entries);
    }

    private static function approvalSection(): Section
    {
        return Section::make('Quy trình duyệt')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->columns(4)
            ->columnSpanFull()
            ->visible(fn (Quotation $record): bool => $record->submitted_at !== null)
            ->schema([
                TextEntry::make('submitted_at')
                    ->label('Gửi duyệt lúc')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('approver.name')
                    ->label('Người duyệt')
                    ->placeholder('—'),
                TextEntry::make('approved_at')
                    ->label('Duyệt lúc')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('sent_at')
                    ->label('Gửi khách lúc')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('accepted_at')
                    ->label('Khách chấp nhận lúc')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('approval_note')
                    ->label('Ghi chú của người duyệt')
                    ->placeholder('—')
                    ->columnSpan(3),
            ]);
    }
}
