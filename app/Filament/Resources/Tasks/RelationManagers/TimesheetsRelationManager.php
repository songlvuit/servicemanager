<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Filament\Resources\Timesheets\Schemas\TimesheetForm;
use App\Filament\Resources\Timesheets\Tables\TimesheetsTable;
use App\Filament\Support\Access;
use App\Services\Tasks\TaskLabor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Chấm công" của công việc: công (buổi) của từng người trong công việc
 * này (mục 28). Công tự sinh theo phân công — xem TaskLabor; ở đây người quản
 * lý xem tổng theo từng người, chỉnh số buổi / đơn giá, hoặc nhập thêm.
 */
class TimesheetsRelationManager extends RelationManager
{
    protected static string $relationship = 'timesheets';

    protected static ?string $title = 'Chấm công';

    protected static ?string $modelLabel = 'công';

    protected static ?string $pluralModelLabel = 'công';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('timesheets.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(TimesheetForm::components(withTask: false));
    }

    public function table(Table $table): Table
    {
        return TimesheetsTable::configure($table, withTask: false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user'])->visibleTo(auth()->user()))
            ->heading('Công của nhân sự')
            ->description('Tự tính theo phân công: mỗi người trong ê-kíp một dòng cho mỗi ngày của công việc. Sửa một dòng để chỉnh số buổi hoặc đơn giá; nhập 0 buổi nếu không tính công.')
            ->defaultGroup(Access::can('timesheets.view_all') ? 'user.name' : null)
            ->defaultSort('work_date')
            ->headerActions([
                Action::make('syncLabor')
                    ->label('Tính lại theo phân công')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->tooltip('Sinh lại các dòng công tự động theo người, ngày, giờ hiện tại của công việc. Dòng nhập tay giữ nguyên.')
                    ->visible(fn (): bool => Access::can('timesheets.view_all') && Access::can('timesheets.create') && ! $this->getOwnerRecord()->isCancelled())
                    ->action(function (): void {
                        $result = app(TaskLabor::class)->sync($this->getOwnerRecord());

                        Notification::make()
                            ->title(array_sum($result) === 0
                                ? 'Công đã khớp với phân công.'
                                : "Đã thêm {$result['created']}, cập nhật {$result['updated']}, bỏ {$result['removed']} dòng công.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->label('Thêm công')
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isCancelled()),
            ]);
    }
}
