<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Access;
use App\Models\Quotation;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Số báo giá')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->description(fn (Quotation $record): ?string => $record->customer?->name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quotation_date')
                    ->label('Ngày báo giá')
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label('Hiệu lực đến')
                    ->date()
                    ->color(fn (Quotation $record): ?string => $record->isPastValidity()
                        && in_array($record->status, [QuotationStatus::Approved, QuotationStatus::Sent], true)
                            ? 'danger'
                            : null)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total')
                    ->label('Tổng cộng')
                    ->formatStateUsing(fn (mixed $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('Tổng')
                            ->formatStateUsing(fn (mixed $state): string => Money::format($state)),
                    ),
                TextColumn::make('estimated_margin')
                    ->label('Biên LN')
                    ->formatStateUsing(fn (mixed $state): string => Money::percent((string) $state))
                    ->color(fn (Quotation $record): string => $record->hasLowMargin() ? 'danger' : 'success')
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => Access::canSeeCosts()),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('creator.name')
                    ->label('Người lập')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(QuotationStatus::class)
                    ->multiple(),
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Quotation $record): bool => QuotationResource::canEdit($record)),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có báo giá nào.')
            ->emptyStateDescription('Lập báo giá đầu tiên cho khách hàng.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo báo giá'),
            ]);
    }
}
