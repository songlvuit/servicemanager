<?php

namespace App\Filament\Pages\Reports;

use App\Enums\BudgetCategory;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TableExport;
use App\Models\CostLine;
use App\Models\Project;
use App\Models\Supplier;
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
 * chi đã thanh toán, đơn mua hàng đã nhận hàng và giờ công. Khoản không gắn
 * dự án được xếp vào "Chi phí chung".
 */
class CostReport extends ReportPage
{
    /**
     * Tên hiển thị cho các khoản chi không gắn dự án nào.
     */
    private const OVERHEAD = 'Chi phí chung';

    protected static ?string $slug = 'reports/costs';

    protected static ?string $title = 'Báo cáo chi phí';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 42;

    protected ?string $subheading = 'Chi phí thực tế trước thuế, gồm cả chi phí chung không gắn dự án: đề nghị chi đã thanh toán, đơn mua hàng đã nhận hàng, giờ công.';

    protected function getHeaderActions(): array
    {
        return [
            TableExport::make('profit_reports.export', 'bao-cao-chi-phi', 'Báo cáo chi phí', fn (): array => [
                'Ngày' => 'cost_date',
                'Nguồn' => fn (CostLine $line): string => $line->sourceLabel(),
                'Số chứng từ' => 'reference',
                'Mã dự án' => 'project.code',
                'Dự án' => fn (CostLine $line): string => $line->project?->name ?? self::OVERHEAD,
                'Công việc' => 'task.name',
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
            ->query(fn (): Builder => CostLine::query()->with(['project', 'task', 'supplier']))
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
                TextColumn::make('project.name')
                    ->label('Dự án')
                    ->placeholder(self::OVERHEAD)
                    ->weight(FontWeight::Medium)
                    ->description(fn (CostLine $record): ?string => $record->project?->code)
                    ->url(fn (CostLine $record): ?string => $record->project !== null && ProjectResource::canView($record->project)
                        ? ProjectResource::getUrl('view', ['record' => $record->project])
                        : null)
                    ->wrap(),
                TextColumn::make('task.name')
                    ->label('Công việc')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
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
                SelectFilter::make('project_id')
                    ->label('Dự án')
                    ->options(fn (): array => Project::query()->withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple(),
                Filters::withoutProject('Chỉ chi phí chung (không gắn dự án)'),
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
                Group::make('project.name')
                    ->label('Dự án')
                    ->getTitleFromRecordUsing(fn (CostLine $record): string => $record->project?->name ?? self::OVERHEAD)
                    ->collapsible(),
                Group::make('task.name')
                    ->label('Công việc')
                    ->getTitleFromRecordUsing(fn (CostLine $record): string => $record->task?->name ?? 'Không gắn công việc')
                    ->collapsible(),
                Group::make('category')->label('Nhóm chi phí')->collapsible(),
                Group::make('supplier.name')->label('Nhà cung cấp')->collapsible(),
            ])
            ->defaultSort('cost_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('Chưa có chi phí thực tế.')
            ->emptyStateDescription('Chi phí xuất hiện khi đề nghị chi được thanh toán, hàng được nhận hoặc giờ công được chấm.');
    }
}
