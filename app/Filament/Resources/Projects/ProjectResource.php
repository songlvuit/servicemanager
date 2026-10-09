<?php

namespace App\Filament\Resources\Projects;

use App\Filament\RelationManagers\ActivityRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectBoard;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\AcceptancesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\BudgetItemsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Projects\RelationManagers\PurchasesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Projects\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Filament\Resources\Projects\Widgets\ProjectStatsOverview;
use App\Filament\Support\VietnameseLabels;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProjectResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Project::class;

    protected static ?string $slug = 'projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Dự án';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'dự án';

    protected static ?string $pluralModelLabel = 'dự án';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    /**
     * Các tab trên trang chi tiết dự án. Tab của các module sau (chi phí, mua
     * hàng, chấm công, nghiệm thu, hóa đơn) được thêm vào đây.
     */
    public static function getRelations(): array
    {
        return [
            'budgetItems' => BudgetItemsRelationManager::class,
            'tasks' => TasksRelationManager::class,
            'scheduleEntries' => ScheduleRelationManager::class,
            'expenses' => ExpensesRelationManager::class,
            'purchaseOrders' => PurchasesRelationManager::class,
            'timesheets' => TimesheetsRelationManager::class,
            'acceptances' => AcceptancesRelationManager::class,
            'invoices' => InvoicesRelationManager::class,
            'memberships' => MembersRelationManager::class,
            'attachments' => AttachmentsRelationManager::class,
            'audits' => ActivityRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            ProjectStatsOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
            'board' => ProjectBoard::route('/{record}/board'),
        ];
    }

    /**
     * Người không có quyền "Xem tất cả" chỉ thấy dự án mình tham gia.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'manager'])
            ->visibleTo(auth()->user())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Dự án đã huỷ không sửa được; chỉ xoá được dự án chưa phát sinh chi phí
     * — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Project
            && ! $record->trashed()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Project
            && $record->isDeletable()
            && parent::canDelete($record);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'customer.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return "{$record->code} — {$record->name}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Khách hàng' => (string) $record->customer?->name,
            'Trạng thái' => $record->status->getLabel(),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }
}
