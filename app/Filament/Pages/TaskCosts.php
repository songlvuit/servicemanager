<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\Access;
use App\Filament\Support\TableExport;
use App\Filament\Support\TaskCostsTable;
use App\Models\TaskCostItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * "Chi phí theo công việc": với mỗi công việc, liệt kê MỌI chi phí liên quan —
 * công của nhân sự, thuê thiết bị / nhà cung cấp (mua hàng), đi lại và các đề
 * nghị chi khác — gom theo công việc, có tổng từng công việc. Khoản không gắn
 * công việc nào nằm ở nhóm "Chi phí chung".
 *
 * Khác Báo cáo chi phí (chỉ phần thực tế, theo tháng, cho người xem báo cáo
 * tài chính): trang này gồm cả phần đã cam kết và dành cho người quản lý chi
 * phí của công việc. Chỉ người được xem chi phí nội bộ mới vào được; người
 * không xem được mọi công việc chỉ thấy chi phí của công việc mình được xem.
 */
class TaskCosts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'task-costs';

    protected static ?string $title = 'Chi phí theo công việc';

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    // Ngay sau mục "Chi phí" (đề nghị chi).
    protected static ?int $navigationSort = 41;

    protected ?string $subheading = 'Mọi chi phí của từng công việc trên một danh sách: công của nhân sự, đề nghị chi, mua hàng. Số trước thuế; "Tổng" là chi phí đã cam kết, "Trong đó thực tế" là phần đã chi.';

    public static function canAccess(): bool
    {
        return Access::canSeeCosts() && Access::can('tasks.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expenses')
                ->label('Đề nghị chi')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->visible(fn (): bool => ExpenseResource::canViewAny())
                ->url(fn (): string => ExpenseResource::getUrl('index')),
            TableExport::make('tasks.export', 'chi-phi-theo-cong-viec', 'Chi phí theo công việc', TaskCostsTable::exportColumns()),
        ];
    }

    public function table(Table $table): Table
    {
        return TaskCostsTable::configure($table)
            ->query(fn (): Builder => static::visibleItems()->with(['task', 'supplier', 'user']))
            ->defaultGroup('task.name');
    }

    /**
     * @return Builder<TaskCostItem>
     */
    public static function visibleItems(): Builder
    {
        $user = auth()->user();

        return TaskCostItem::query()
            // Không xem được mọi công việc: chỉ chi phí của công việc mình được xem, không có chi phí chung.
            ->when(! Access::can('tasks.view_all'), fn (Builder $query) => $query->whereHas('task', fn (Builder $query) => $query->visibleTo($user)));
    }
}
