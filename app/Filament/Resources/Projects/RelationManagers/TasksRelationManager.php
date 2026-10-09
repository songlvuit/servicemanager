<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Công việc" của dự án (dạng bảng); dạng Kanban mở bằng nút "Bảng Kanban".
 */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Công việc';

    protected static ?string $modelLabel = 'công việc';

    protected static ?string $pluralModelLabel = 'công việc';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedRectangleStack;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('tasks.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(TaskForm::components(withProject: false));
    }

    public function table(Table $table): Table
    {
        return TasksTable::configure($table, withProject: false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignee', 'parent'])->visibleTo(auth()->user()))
            ->headerActions([
                Action::make('board')
                    ->label('Bảng Kanban')
                    ->icon(Heroicon::OutlinedViewColumns)
                    ->color('gray')
                    ->url(fn (): string => ProjectResource::getUrl('board', ['record' => $this->getOwnerRecord()])),
                CreateAction::make()
                    ->label('Thêm công việc')
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isClosed() && ! $this->getOwnerRecord()->trashed()),
            ]);
    }
}
