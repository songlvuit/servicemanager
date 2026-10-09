<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Services\Quotations\QuotationWorkflow;
use Filament\Resources\Pages\CreateRecord;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    /**
     * Hạng mục đã được lưu xong: tính và ghi các cột tổng hợp ở server.
     */
    protected function afterCreate(): void
    {
        app(QuotationWorkflow::class)->recalculate($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
