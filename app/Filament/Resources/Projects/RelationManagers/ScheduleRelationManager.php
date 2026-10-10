<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Pages\WorkCalendar;
use App\Models\ScheduleEntry;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Lịch" của dự án: các lịch làm việc gắn với dự án. Bấm vào dòng để mở
 * lịch trên trang Lịch làm việc.
 */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'scheduleEntries';

    protected static ?string $title = 'Lịch';

    protected static ?string $modelLabel = 'lịch';

    protected static ?string $pluralModelLabel = 'lịch';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedCalendarDays;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['type', 'crew']))
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Thời gian')
                    ->formatStateUsing(fn (ScheduleEntry $record): string => $record->isMultiDay()
                        ? $record->timeLabel()
                        : $record->starts_at->format('d/m/Y').' · '.$record->timeLabel())
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Lịch')
                    ->weight(FontWeight::Medium)
                    ->description(fn (ScheduleEntry $record): ?string => $record->type?->name)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('crew_label')
                    ->label('Ê-kíp')
                    ->state(fn (ScheduleEntry $record): string => $record->crewLabel())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('location')
                    ->label('Địa điểm')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('timesheets_logged_at')
                    ->label('Giờ công')
                    ->formatStateUsing(fn (): string => 'Đã ghi')
                    ->placeholder('Chưa ghi')
                    ->toggleable(),
            ])
            ->recordUrl(fn (ScheduleEntry $record): string => WorkCalendar::entryUrl($record))
            ->defaultSort('starts_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading('Dự án chưa có lịch nào.')
            ->emptyStateDescription('Lịch tự lấy từ các công việc có ngày bắt đầu của dự án. Thêm công việc ở tab Công việc để nó hiện ở đây và trên Lịch làm việc.');
    }
}
