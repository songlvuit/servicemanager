<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Rule 1 (chỉ tạo dự án từ hợp đồng đang hiệu lực) được kiểm tra ở form và
 * lần nữa trong model Project khi lưu.
 */
class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
