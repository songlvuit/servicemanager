<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageTasks extends ManageRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm công việc'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'mine' => Tab::make('Việc của tôi')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()->where('assignee_id', auth()->id())),
            'open' => Tab::make('Đang mở')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),
            'overdue' => Tab::make('Quá hạn')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->overdue()),
            'all' => Tab::make('Tất cả'),
        ];
    }
}
