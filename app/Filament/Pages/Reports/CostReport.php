<?php

namespace App\Filament\Pages\Reports;

use App\Enums\BudgetCategory;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Columns;
use App\Filament\Support\ContractLink;
use App\Filament\Support\Filters;
use App\Filament\Support\TableExport;
use App\Filament\Support\TaskField;
use App\Models\CostLine;
use App\Models\Supplier;
use App\Models\Task;
use BackedEnum;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Báo cáo chi phí (mục 62): từng khoản CHI PHÍ THỰC TẾ trước thuế — đề nghị
 * chi đã thanh toán, đơn mua hàng đã nhận hàng và công của nhân sự. Khoản không gắn
 * công việc nào được xếp vào "Chi phí chung".
 */
class CostReport extends ReportPage
{
    /**
     * Tên hiển thị cho các khoản chi không gắn công việc nào.
     */
    private const OVERHEAD = 'Chi phí chung';

    protected static ?string $slug = 'reports/costs';

    protected static ?string $title = 'Báo cáo chi phí';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 42;

    protected ?string $subheading = 'Chi phí thực tế trước thuế, theo từng công việc và cả chi phí chung không gắn công việc: đề nghị chi đã thanh toán, đơn mua hàng đã nhận hàng, công của nhân sự.';

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('profit_reports.export', 'bao-cao-chi-phi', 'Báo cáo chi phí', fn (): array => [
                'Ngày' => 'cost_date',
                'Nguồn' => fn (CostLine $line): string => $line->sourceLabel(),
                'Số chứng từ' => 'reference',
                'Công việc' => fn (CostLine $line): string => $line->task?->name ?? self::OVERHEAD,
                ...(ContractLink::canSeeContracts() ? ['Hợp đồng' => 'task.contract.code'] : []),
                'Nhóm chi phí' => 'category',
                'Nhà cung cấp' => 'supplier.name',
                'Nội dung' => 'description',
                'Số tiền (trước thuế)' => 'amount',
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CostLine::query()->with(ContractLink::canSeeContracts() ? ['task.contract', 'supplier'] : ['task', 'supplier']))
            ->modelLabel('khoản chi phí')
            ->pluralModelLabel('khoản chi phí')
            ->columns([
                TextColumn::make('cost_date')
                    ->label('Ngày')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Nguồn')
                    ->formatStateUsing(fn (CostLine $record): string => $record->sourceLabel())
                    ->description(fn (CostLine $record): ?string => $record->reference)
                    ->searchable(['reference']),
                TextColumn::make('task.name')
                    ->label('Công việc')
                    ->placeholder(self::OVERHEAD)
                    ->weight(FontWeight::Medium)
                    ->url(fn (CostLine $record): ?string => $record->task !== null && (auth()->user()?->can('view', $record->task) ?? false)
                        ? TaskResource::getUrl('view', ['record' => $record->task])
                        : null)
                    ->wrap(),
                ...(ContractLink::canSeeContracts() ? [
                    TextColumn::make('task.contract.code')
                        ->label('Hợp đồng')
                        ->placeholder('—')
                        ->toggleable(),
                ] : []),
                TextColumn::make('category')
                    ->label('Nhóm chi phí')
                    ->badge(),
                TextColumn::make('supplier.name')
                    ->label('Nhà cung cấp')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Nội dung')
                    ->placeholder('—')
                    ->limit(60)
                    ->searchable()
                    ->toggleable(),
                Columns::money('amount', 'Số tiền', summarize: true)
                    ->weight(FontWeight::SemiBold),
            ])
            ->filters([
                Filters::dateRange('cost_date', 'Ngày'),
                SelectFilter::make('task_id')
                    ->label('Công việc')
                    ->options(fn (): array => Task::query()->orderByDesc('id')->limit(500)->get(['id', 'name', 'start_date'])
                        ->mapWithKeys(fn (Task $task): array => [$task->getKey() => TaskField::label($task)])->all())
                    ->searchable()
                    ->multiple(),
                ...(ContractLink::canSeeContracts() ? [
                    SelectFilter::make('contract')
                        ->label('Hợp đồng')
                        ->options(fn (): array => ContractLink::contractOptions())
                        ->searchable()
                        ->query(fn (Builder $query, array $data): Builder => $query->when(
                            $data['value'] ?? null,
                            fn (Builder $query, mixed $contract): Builder => $query->whereHas('task', fn (Builder $query) => $query->where('contract_id', $contract)),
                        )),
                ] : []),
                Filters::withoutTask('Chỉ chi phí chung (không gắn công việc)'),
                SelectFilter::make('category')
                    ->label('Nhóm chi phí')
                    ->options(BudgetCategory::class)
                    ->multiple(),
                SelectFilter::make('supplier_id')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => Supplier::query()->withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(CostLine::SOURCES),
            ])
            ->groups([
                Group::make('task.name')
                    ->label('Công việc')
                    ->getTitleFromRecordUsing(fn (CostLine $record): string => $record->task?->name ?? self::OVERHEAD)
                    ->collapsible(),
                Group::make('category')->label('Nhóm chi phí')->collapsible(),
                Group::make('supplier.name')->label('Nhà cung cấp')->collapsible(),
            ])
            ->defaultSort('cost_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('Chưa có chi phí thực tế.')
            ->emptyStateDescription('Chi phí xuất hiện khi đề nghị chi được thanh toán, hàng được nhận hoặc nhân sự được phân công vào công việc.');
    }
}
