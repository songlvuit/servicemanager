<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceStatus;
use App\Filament\Support\Access;
use App\Models\Service;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServicesTable
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
                TextColumn::make('name')
                    ->label('Tên dịch vụ')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Nhóm')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('default_unit')
                    ->label('ĐVT')
                    ->placeholder('—'),
                TextColumn::make('default_price')
                    ->label('Đơn giá bán')
                    ->formatStateUsing(fn (mixed $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('default_cost')
                    ->label('Giá vốn')
                    ->formatStateUsing(fn (mixed $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => Access::canSeeCosts()),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Nhóm')
                    ->options(fn (): array => Service::query()
                        ->whereNotNull('category')
                        ->orderBy('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->all()),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ServiceStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('code')
            ->emptyStateHeading('Chưa có dịch vụ nào.')
            ->emptyStateDescription('Khai báo các dịch vụ công ty cung cấp để chọn nhanh khi lập báo giá.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo dịch vụ'),
            ]);
    }
}
