<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\TableExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            TableExport::make('customers.export', 'khach-hang', 'Danh sách khách hàng', fn (): array => [
                'Mã' => 'code',
                'Tên khách hàng' => 'name',
                'Loại hình' => 'company_type',
                'Mã số thuế' => 'tax_code',
                'Ngành nghề' => 'industry',
                'Điện thoại' => 'phone',
                'Email' => 'email',
                'Website' => 'website',
                'Địa chỉ' => 'address',
                'Tỉnh / Thành phố' => 'province',
                'Trạng thái' => 'status',
                'Ngày tạo' => 'created_at',
            ]),
        ];
    }
}
