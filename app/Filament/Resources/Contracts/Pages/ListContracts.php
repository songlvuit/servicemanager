<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $byStatus = fn (ContractStatus ...$statuses): \Closure => fn (Builder $query): Builder => $query->whereIn(
            'status',
            array_map(fn (ContractStatus $status): string => $status->value, $statuses),
        );

        return [
            'all' => Tab::make('Tất cả'),
            'draft' => Tab::make('Nháp')
                ->modifyQueryUsing($byStatus(ContractStatus::Draft)),
            'pending' => Tab::make('Chờ ký')
                ->modifyQueryUsing($byStatus(ContractStatus::PendingSignature)),
            'active' => Tab::make('Đang hiệu lực')
                ->modifyQueryUsing($byStatus(ContractStatus::Active)),
            'expiring' => Tab::make('Sắp hết hạn')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->expiringWithin((int) config('job.contract_expiry_warning_days', 30))),
            'closed' => Tab::make('Hoàn thành / Đã huỷ')
                ->modifyQueryUsing($byStatus(ContractStatus::Completed, ContractStatus::Cancelled)),
        ];
    }
}
