<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trang chi tiết báo giá: nơi thực hiện các bước của quy trình duyệt.
 */
class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Báo giá';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedDocumentText;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Báo giá '.$this->getRecord()->code;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Điều kiện theo trạng thái áp dụng cả với Super Admin (người được bỏ qua policy).
            EditAction::make()
                ->visible(fn (Quotation $record): bool => QuotationResource::canEdit($record)),
            QuotationActions::submit(),
            QuotationActions::approve(),
            QuotationActions::reject(),
            QuotationActions::markSent(),
            QuotationActions::markAccepted(),
            QuotationActions::markDeclined(),
            QuotationActions::revise(),
            // Khách đã chấp nhận → lập hợp đồng từ báo giá này.
            Action::make('createContract')
                ->label('Tạo hợp đồng')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('success')
                ->visible(fn (Quotation $record): bool => ! $record->trashed() && $record->isAwaitingContract() && ContractResource::canCreate())
                ->url(fn (Quotation $record): string => ContractResource::getUrl('create', ['quotation' => $record->getKey()])),
            ActionGroup::make([
                QuotationActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (Quotation $record): bool => ! $record->trashed() && QuotationResource::canDelete($record)),
                RestoreAction::make(),
            ]),
        ];
    }
}
