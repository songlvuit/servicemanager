<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Services\Quotations\QuotationWorkflow;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Chỉ mở được với báo giá nháp (QuotationResource::canEdit()).
 */
class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (Quotation $record): bool => QuotationResource::canDelete($record)),
        ];
    }

    protected function afterSave(): void
    {
        app(QuotationWorkflow::class)->recalculate($this->getRecord());
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
