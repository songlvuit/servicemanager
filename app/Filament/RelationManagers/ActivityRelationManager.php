<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Hoạt động" (mục 8): lịch sử thao tác trên chính bản ghi đang xem, lấy
 * từ nhật ký thao tác. Ai xem được bản ghi thì xem được tab này; giá trị cũ /
 * mới của từng trường và địa chỉ IP chỉ hiện với người có quyền xem Audit Log.
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    protected static ?string $title = 'Hoạt động';

    protected static ?string $modelLabel = 'hoạt động';

    protected static ?string $pluralModelLabel = 'hoạt động';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

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
        return AuditLogResource::logsTable($table, withSubject: false, withDetails: Access::can('audit_logs.view'))
            ->recordTitleAttribute('label')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->emptyStateHeading('Chưa có hoạt động nào.')
            ->emptyStateDescription('Các lần tạo, sửa và đổi trạng thái của bản ghi này sẽ được ghi lại tại đây.');
    }
}
