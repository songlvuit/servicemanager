<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Filament\Support\ContractLink;
use App\Filament\Support\TableExport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManageTasks extends ManageRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('Bảng Kanban')
                ->icon(Heroicon::OutlinedViewColumns)
                ->color('gray')
                ->url(fn (): string => TaskResource::getUrl('board')),
            CreateAction::make()
                ->label('Thêm công việc'),
            // Cột tiền chỉ có khi người xuất được xem chi phí nội bộ; cột hợp đồng, báo giá
            // chỉ có khi họ được xem hợp đồng, báo giá.
            TableExport::make('tasks.export', 'cong-viec', 'Danh sách công việc', fn (): array => [
                'Công việc' => 'name',
                'Công việc cha' => 'parent.name',
                'Khách hàng' => 'customer.name',
                ...(ContractLink::canSeeContracts() ? ['Hợp đồng' => 'contract.code'] : []),
                ...(ContractLink::canSeeQuotations() ? ['Báo giá' => 'quotation.code'] : []),
                'Người phụ trách' => 'assignee.name',
                'Trạng thái' => 'status',
                'Mức ưu tiên' => 'priority',
                'Ngày bắt đầu' => 'start_date',
                'Hạn hoàn thành' => 'due_date',
                'Tiến độ (%)' => 'progress',
                'Buổi (công) dự kiến' => 'estimated_sessions',
                'Buổi (công) thực tế' => 'actual_sessions',
                ...(Access::canSeeCosts() ? [
                    'Ngân sách' => 'budget',
                    'Chi phí đã cam kết' => 'committed_cost',
                    'Chi phí thực tế' => 'actual_cost',
                ] : []),
            ]),
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
            // Ngân sách là thông tin nội bộ: tab này chỉ có với người xem được chi phí.
            ...(Access::canSeeCosts() ? [
                'over_budget' => Tab::make('Vượt ngân sách')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->overBudget()),
            ] : []),
            'all' => Tab::make('Tất cả'),
        ];
    }
}
