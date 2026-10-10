<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\PurchasesRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Support\VietnameseLabels;
use App\Models\Task;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TaskResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Công việc';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'công việc';

    protected static ?string $pluralModelLabel = 'công việc';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(TaskForm::components());
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ExpensesRelationManager::class,
            PurchasesRelationManager::class,
            TimesheetsRelationManager::class,
            ScheduleRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
        ];
    }

    /**
     * Người không có quyền "Xem tất cả" chỉ thấy công việc được giao cho mình.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['project', 'assignee', 'parent', 'schedule.type', 'schedule.crew', 'customer'])
            ->visibleTo(auth()->user());
    }

    /**
     * Số công việc đang mở được giao cho người đang đăng nhập.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = Task::query()->open()->where('assignee_id', auth()->id())->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Công việc đang mở của bạn';
    }
}
