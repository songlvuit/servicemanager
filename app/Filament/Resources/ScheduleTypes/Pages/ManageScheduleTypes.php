<?php

namespace App\Filament\Resources\ScheduleTypes\Pages;

use App\Filament\Resources\ScheduleTypes\ScheduleTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageScheduleTypes extends ManageRecords
{
    protected static string $resource = ScheduleTypeResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Các loại việc hiện trên Lịch làm việc. Loại đã có lịch thì không xoá được — hãy tắt "Đang dùng" thay vì xoá.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm loại lịch'),
        ];
    }
}
