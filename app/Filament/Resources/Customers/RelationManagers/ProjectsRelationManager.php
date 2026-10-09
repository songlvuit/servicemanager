<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dự án của khách hàng (chỉ xem; bấm vào dòng để mở dự án).
 */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = 'Dự án';

    protected static ?string $modelLabel = 'dự án';

    protected static ?string $pluralModelLabel = 'dự án';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBriefcase;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('projects.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->visibleTo(auth()->user()))
            ->columns([
                Columns::code(label: 'Mã dự án'),
                TextColumn::make('name')
                    ->label('Tên dự án')
                    ->wrap()
                    ->searchable(),
                Columns::money('contract_value', 'Doanh thu'),
                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->suffix('%')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có dự án nào.')
            ->emptyStateDescription('Dự án triển khai cho khách hàng này sẽ hiển thị tại đây.');
    }
}
