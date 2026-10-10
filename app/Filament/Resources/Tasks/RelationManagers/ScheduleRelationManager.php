<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Filament\Resources\Projects\RelationManagers\ScheduleRelationManager as ProjectSchedule;
use Filament\Tables\Table;

/**
 * Tab "Lịch" của công việc: các lịch làm việc gắn với công việc này.
 */
class ScheduleRelationManager extends ProjectSchedule
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading('Công việc chưa có lịch nào.')
            ->emptyStateDescription('Đặt ngày bắt đầu cho công việc (nút Sửa công việc) để nó tự hiện trên Lịch làm việc.');
    }
}
