<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang chi tiết hóa đơn: số tiền, các lần thanh toán, tài liệu và các nút thao tác.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Hóa đơn';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedReceiptPercent;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Hóa đơn '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->customer?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Invoice $record): bool => InvoiceResource::canEdit($record)),
            InvoiceActions::issue(),
            InvoiceActions::recordPayment(),
            ActionGroup::make([
                InvoiceActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (Invoice $record): bool => InvoiceResource::canDelete($record)),
                RestoreAction::make(),
            ]),
        ];
    }
}
