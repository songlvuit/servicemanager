<?php

namespace App\Filament\Resources\Opportunities\Tables;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Customers\RelationManagers\OpportunitiesRelationManager;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Opportunity;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Tên cơ hội')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->searchable()
                    ->sortable()
                    ->hiddenOn(OpportunitiesRelationManager::class),
                TextColumn::make('estimated_value')
                    ->label('Giá trị ước tính')
                    ->formatStateUsing(fn (mixed $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('Tổng')
                            ->formatStateUsing(fn (mixed $state): string => Money::format($state)),
                    ),
                TextColumn::make('probability')
                    ->label('Xác suất')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('expected_revenue')
                    ->label('Doanh thu kỳ vọng')
                    ->state(fn (Opportunity $record): string => Money::format($record->expected_revenue))
                    ->alignEnd(),
                TextColumn::make('expected_close_date')
                    ->label('Dự kiến chốt')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('salesOwner.name')
                    ->label('Phụ trách')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Giai đoạn')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Giai đoạn')
                    ->options(OpportunityStatus::class)
                    ->multiple(),
                SelectFilter::make('sales_owner_id')
                    ->label('Người phụ trách')
                    ->relationship('salesOwner', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('createQuotation')
                    ->label('Tạo báo giá')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->color('gray')
                    ->visible(fn (Opportunity $record): bool => $record->status->isOpen() && QuotationResource::canCreate())
                    ->url(fn (Opportunity $record): string => QuotationResource::getUrl('create', [
                        'customer' => $record->customer_id,
                        'opportunity' => $record->getKey(),
                    ])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có cơ hội nào.')
            ->emptyStateDescription('Ghi lại nhu cầu của khách hàng để theo dõi tới khi chốt báo giá.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo cơ hội'),
            ]);
    }
}
