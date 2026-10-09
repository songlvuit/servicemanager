<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected ?string $subheading = 'Mọi thao tác quan trọng trong hệ thống. Nhật ký chỉ để xem, không sửa và không xoá được.';
}
