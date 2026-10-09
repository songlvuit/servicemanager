<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\Access;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use UnitEnum;

/**
 * Nền chung của các báo cáo tài chính (mục 62): một bảng có bộ lọc, dòng
 * tổng và nút xuất dữ liệu. Chỉ người có quyền "Báo cáo tài chính" xem được.
 */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Tài chính';

    public static function canAccess(): bool
    {
        return Access::can('profit_reports.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}
