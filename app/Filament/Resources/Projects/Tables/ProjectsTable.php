<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\CostStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Mã dự án'),
                TextColumn::make('name')
                    ->label('Tên dự án')
                    ->description(fn (Project $record): ?string => $record->customer?->name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('manager.name')
                    ->label('Quản lý dự án')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('end_date')
                    ->label('Hạn hoàn thành')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Project $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
                Columns::money('contract_value', 'Doanh thu', summarize: true),
                Columns::money('actual_cost', 'Chi phí thực tế', summarize: true)
                    ->visible(fn (): bool => Access::canSeeCosts()),
                TextColumn::make('cost_status')
                    ->label('Ngân sách')
                    ->state(fn (Project $record): CostStatus => $record->costStatus())
                    ->badge()
                    ->visible(fn (): bool => Access::canSeeCosts()),
                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(ProjectStatus::class)
                    ->multiple(),
                SelectFilter::make('project_manager_id')
                    ->label('Quản lý dự án')
                    ->options(fn (): array => TaskForm::userOptions())
                    ->searchable(),
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
                    ->visible(fn (Project $record): bool => ProjectResource::canEdit($record)),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có dự án nào.')
            ->emptyStateDescription('Tạo dự án từ một hợp đồng đang hiệu lực.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo dự án'),
            ]);
    }
}
