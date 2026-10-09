<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Danh sách báo giá của khách hàng (chỉ xem). Bấm vào một dòng để mở báo giá;
 * việc sửa và duyệt thực hiện trong màn hình Báo giá.
 */
class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Báo giá';

    protected static ?string $modelLabel = 'báo giá';

    protected static ?string $pluralModelLabel = 'báo giá';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentText;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Số báo giá')
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('quotation_date')
                    ->label('Ngày báo giá')
                    ->date()
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Tổng cộng')
                    ->formatStateUsing(fn (mixed $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Quotation $record): string => QuotationResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createQuotation')
                    ->label('Tạo báo giá')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->trashed() && QuotationResource::canCreate())
                    ->url(fn (): string => QuotationResource::getUrl('create', ['customer' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('quotation_date', 'desc')
            ->emptyStateHeading('Chưa có báo giá nào.')
            ->emptyStateDescription('Các báo giá lập cho khách hàng này sẽ hiển thị tại đây.');
    }
}
