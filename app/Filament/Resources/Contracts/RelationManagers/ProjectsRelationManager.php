<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Columns;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Các dự án triển khai theo hợp đồng (chỉ xem; bấm vào dòng để mở dự án).
 */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = 'Dự án';

    protected static ?string $modelLabel = 'dự án';

    protected static ?string $pluralModelLabel = 'dự án';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBriefcase;

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
                TextColumn::make('manager.name')
                    ->label('Quản lý dự án')
                    ->placeholder('—'),
                Columns::money('contract_value', 'Giá trị'),
                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->suffix('%')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createProject')
                    ->label('Tạo dự án')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canCreateProject())
                    ->url(fn (): string => ProjectResource::getUrl('create', ['contract' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có dự án nào.')
            ->emptyStateDescription('Dự án chỉ tạo được khi hợp đồng đang hiệu lực.');
    }

    private function canCreateProject(): bool
    {
        $contract = $this->getOwnerRecord();

        return ! $contract->trashed() && $contract->isActive() && ProjectResource::canCreate();
    }
}
