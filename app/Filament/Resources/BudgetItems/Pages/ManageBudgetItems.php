<?php

namespace App\Filament\Resources\BudgetItems\Pages;

use App\Filament\Resources\BudgetItems\BudgetItemResource;
use App\Filament\Support\TableExport;
use App\Models\BudgetItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBudgetItems extends ManageRecords
{
    protected static string $resource = BudgetItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm ngân sách'),
            TableExport::make('budgets.export', 'ngan-sach', 'Ngân sách và chi phí dự án', fn (): array => [
                'Mã dự án' => 'project.code',
                'Dự án' => 'project.name',
                'Nhóm' => 'category',
                'Hạng mục' => 'description',
                'Ngân sách' => 'planned_amount',
                'Đã cam kết' => 'committed_amount',
                'Thực tế' => 'actual_amount',
                'Còn lại' => 'variance',
                'Tình trạng' => fn (BudgetItem $item): string => $item->costStatus()->getLabel(),
            ]),
        ];
    }
}
