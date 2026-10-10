<?php

namespace App\Filament\Resources\Timesheets;

use App\Filament\Resources\Timesheets\Pages\ManageTimesheets;
use App\Filament\Resources\Timesheets\Schemas\TimesheetForm;
use App\Filament\Resources\Timesheets\Tables\TimesheetsTable;
use App\Filament\Support\Access;
use App\Filament\Support\VietnameseLabels;
use App\Models\Timesheet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TimesheetResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Timesheet::class;

    protected static ?string $slug = 'timesheets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static ?string $navigationLabel = 'Chấm công';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'công';

    protected static ?string $pluralModelLabel = 'chấm công';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(TimesheetForm::components());
    }

    public static function table(Table $table): Table
    {
        return TimesheetsTable::configure($table)
            // Người quản lý công của mọi người: gom theo nhân viên để thấy ngay tổng buổi, tổng tiền từng người trong tháng.
            ->defaultGroup(Access::can('timesheets.view_all') ? 'user.name' : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTimesheets::route('/'),
        ];
    }

    /**
     * Người không có quyền "Xem tất cả" chỉ thấy công của nhân sự của mình.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'task'])
            ->visibleTo(auth()->user());
    }
}
