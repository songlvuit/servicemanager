<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Columns;
use App\Models\Contract;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Số hợp đồng'),
                TextColumn::make('title')
                    ->label('Tên hợp đồng')
                    ->description(fn (Contract $record): ?string => $record->customer?->name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                Columns::money('contract_value', 'Giá trị trước thuế', summarize: true),
                TextColumn::make('sign_date')
                    ->label('Ngày ký')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('end_date')
                    ->label('Ngày kết thúc')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Contract $record): ?string => $record->isExpiringSoon() ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('creator.name')
                    ->label('Người tạo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ContractStatus::class)
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
                    ->visible(fn (Contract $record): bool => ContractResource::canEdit($record)),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có hợp đồng nào.')
            ->emptyStateDescription('Tạo hợp đồng từ báo giá đã được khách hàng chấp nhận.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo hợp đồng'),
            ]);
    }
}
