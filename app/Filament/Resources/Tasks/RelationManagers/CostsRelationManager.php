<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Filament\Support\Access;
use App\Filament\Support\TableExport;
use App\Filament\Support\TaskCostsTable;
use App\Models\Task;
use App\Models\TaskCostItem;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Chi phí" của công việc: MỌI khoản chi phí liên quan tới công việc trên
 * một danh sách — công của nhân sự, thuê thiết bị / nhà cung cấp, đi lại… —
 * gom theo nhóm chi phí, có tổng từng nhóm. Gồm cả chi phí của các công việc
 * con, nên tổng ở đây khớp với "Đã cam kết" / "Chi phí thực tế" của công việc.
 *
 * Chỉ để xem (bấm một dòng để mở chứng từ gốc); chỉ người được xem chi phí nội
 * bộ mới có tab này.
 */
class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costItems';

    protected static ?string $title = 'Chi phí';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::canSeeCosts();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Task $task */
        $task = $this->getOwnerRecord();

        return TaskCostsTable::configure($table, withTask: $task->children()->exists())
            // Không chỉ chứng từ của chính công việc này: gồm cả các công việc con của nó.
            ->query(fn (): Builder => TaskCostItem::query()->forTask($task)->with(['task', 'supplier', 'user']))
            ->heading('Tất cả chi phí của công việc')
            ->description('Công của nhân sự, đề nghị chi và mua hàng gắn vào công việc này'
                .($task->children()->exists() ? ' và các công việc con' : '')
                .'. Số trước thuế; "Tổng" là chi phí đã cam kết, "Trong đó thực tế" là phần đã chi.')
            ->defaultGroup('category')
            ->headerActions([
                TableExport::make('tasks.export', 'chi-phi-cong-viec', 'Chi phí của công việc '.$task->name, TaskCostsTable::exportColumns()),
            ]);
    }
}
