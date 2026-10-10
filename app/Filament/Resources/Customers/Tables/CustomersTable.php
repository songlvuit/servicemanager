<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Support\Access;
use App\Models\Customer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->weight(FontWeight::Medium)
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên khách hàng')
                    ->description(fn (Customer $record): ?string => filled($record->tax_code) ? "MST: {$record->tax_code}" : null)
                    ->wrap()
                    ->searchable(['name', 'tax_code'])
                    ->sortable(),
                TextColumn::make('company_type')
                    ->label('Loại hình')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('province')
                    ->label('Tỉnh / Thành')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('quotations_count')
                    ->label('Báo giá')
                    ->counts('quotations')
                    ->sortable()
                    // Báo giá là thông tin chỉ người được xem báo giá mới thấy.
                    ->visible(fn (): bool => Access::can('quotations.view')),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(CustomerStatus::class),
                SelectFilter::make('company_type')
                    ->label('Loại hình')
                    ->options(CustomerType::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có khách hàng nào.')
            ->emptyStateDescription('Thêm khách hàng đầu tiên để bắt đầu lập báo giá.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo khách hàng'),
            ]);
    }
}
