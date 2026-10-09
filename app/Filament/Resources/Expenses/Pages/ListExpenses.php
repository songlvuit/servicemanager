<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\TableExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            TableExport::make('expenses.export', 'de-nghi-chi', 'Danh sách đề nghị chi', fn (): array => [
                'Mã' => 'code',
                'Nội dung' => 'description',
                'Mã dự án' => 'project.code',
                'Dự án' => 'project.name',
                'Người đề nghị' => 'employee.name',
                'Nhóm chi phí' => 'category',
                'Nhà cung cấp' => 'vendor.name',
                'Ngày chi' => 'expense_date',
                'Số tiền trước thuế' => 'amount',
                'Thuế' => 'tax',
                'Tổng tiền' => 'total_amount',
                'Trạng thái' => 'status',
                'Người duyệt' => 'approver.name',
                'Thời điểm duyệt' => 'approved_at',
                'Hình thức thanh toán' => 'payment_method',
                'Thời điểm thanh toán' => 'paid_at',
                'Số chứng từ thanh toán' => 'payment_reference',
            ]),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (ExpenseStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (ExpenseStatus $status): string => $status->value, $statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'mine' => Tab::make('Của tôi')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('employee_id', auth()->id())),
            'awaiting_me' => Tab::make('Chờ tôi duyệt')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingApprovalBy(auth()->user())),
            'in_approval' => Tab::make('Đang duyệt')
                ->modifyQueryUsing($byStatus(...ExpenseStatus::inApproval())),
            'approved' => Tab::make('Chờ thanh toán')
                ->modifyQueryUsing($byStatus(ExpenseStatus::Approved)),
            'paid' => Tab::make('Đã thanh toán')
                ->modifyQueryUsing($byStatus(ExpenseStatus::Paid, ExpenseStatus::Completed)),
            'draft' => Tab::make('Nháp / Bị từ chối')
                ->modifyQueryUsing($byStatus(ExpenseStatus::Draft, ExpenseStatus::Rejected)),
        ];
    }
}
