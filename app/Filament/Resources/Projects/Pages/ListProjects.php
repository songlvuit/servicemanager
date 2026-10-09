<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Access;
use App\Filament\Support\TableExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            TableExport::make('projects.export', 'du-an', 'Danh sách dự án', fn (): array => [
                'Mã dự án' => 'code',
                'Tên dự án' => 'name',
                'Khách hàng' => 'customer.name',
                'Quản lý dự án' => 'manager.name',
                'Ngày bắt đầu' => 'start_date',
                'Ngày kết thúc' => 'end_date',
                'Trạng thái' => 'status',
                'Tiến độ (%)' => 'progress',
                'Doanh thu (giá trị hợp đồng)' => 'contract_value',
                ...(Access::canSeeCosts() ? [
                    'Ngân sách' => 'budget',
                    'Chi phí đã cam kết' => 'committed_cost',
                    'Chi phí thực tế' => 'actual_cost',
                    'Lợi nhuận thực tế' => 'actual_profit',
                    'Biên lợi nhuận (%)' => 'profit_margin',
                ] : []),
                'Đã xuất hóa đơn (gồm thuế)' => 'invoiced_amount',
                'Đã thu' => 'collected_amount',
            ]),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (ProjectStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (ProjectStatus $status): string => $status->value, $statuses),
        );

        $tabs = [
            'all' => Tab::make('Tất cả'),
            'active' => Tab::make('Đang thực hiện')
                ->modifyQueryUsing($byStatus(ProjectStatus::Active)),
            'planning' => Tab::make('Lập kế hoạch')
                ->modifyQueryUsing($byStatus(ProjectStatus::Planning)),
            'on_hold' => Tab::make('Tạm dừng')
                ->modifyQueryUsing($byStatus(ProjectStatus::OnHold)),
        ];

        if (Access::canSeeCosts()) {
            $tabs['over_budget'] = Tab::make('Vượt ngân sách')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->overBudget());
        }

        $tabs['closed'] = Tab::make('Hoàn thành / Đã huỷ')
            ->modifyQueryUsing($byStatus(ProjectStatus::Completed, ProjectStatus::Cancelled));

        return $tabs;
    }
}
