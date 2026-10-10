<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang chi tiết khách hàng: tab "Tổng quan" cùng các tab Liên hệ, Cơ hội,
 * Báo giá, Hợp đồng, Hóa đơn, Thanh toán, Tài liệu. Tab Hoạt động được
 * thêm cùng Audit Log (Phase 6).
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Tổng quan';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedInformationCircle;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createQuotation')
                ->label('Tạo báo giá')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('gray')
                ->visible(fn (Customer $record): bool => ! $record->trashed() && QuotationResource::canCreate())
                ->url(fn (Customer $record): string => QuotationResource::getUrl('create', ['customer' => $record->getKey()])),
            EditAction::make(),
        ];
    }
}
