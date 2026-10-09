<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\MarginBand;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Project;
use App\Services\Projects\ProjectCostCollector;
use App\Services\Projects\ProjectFinancials;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Phần thân của dashboard dự án (mục 17, 19, 33): cảnh báo ngân sách, bảng
 * kiểm soát chi phí, lãi lỗ và thông tin chung. Hàng thẻ số liệu phía trên là
 * widget ProjectStatsOverview.
 */
class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $canSeeCosts = Access::canSeeCosts();

        return $schema
            ->components([
                Callout::make(fn (Project $record): string => $record->costStatus()->getLabel())
                    ->description(fn (Project $record): string => (string) $record->costStatus()->message()
                        .' Ngân sách '.Money::format($record->budget).', thực tế '.Money::format($record->actual_cost).'.')
                    ->color(fn (Project $record): string => $record->costStatus()->getColor())
                    ->columnSpanFull()
                    ->visible(fn (Project $record): bool => $canSeeCosts && $record->costStatus()->isAlert()),
                Callout::make('Dự án quá hạn')
                    ->description(fn (Project $record): string => 'Đã quá hạn hoàn thành '.abs((int) $record->daysUntilEnd()).' ngày.')
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Project $record): bool => $record->isOverdue()),

                static::costControlSection()
                    ->visible($canSeeCosts),
                static::profitSection()
                    ->visible($canSeeCosts),
                static::generalSection(),
            ]);
    }

    /**
     * Ngân sách – Cam kết – Thực tế theo từng nhóm chi phí (mục 19).
     */
    private static function costControlSection(): Section
    {
        $money = fn (string $name): TextEntry => TextEntry::make($name)
            ->hiddenLabel()
            ->formatStateUsing(fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0));

        return Section::make('Kiểm soát chi phí')
            ->icon(Heroicon::OutlinedScale)
            ->description('Ngân sách so với chi phí đã cam kết và chi phí thực tế, theo từng nhóm.')
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('cost_breakdown')
                    ->hiddenLabel()
                    ->state(fn (Project $record): array => app(ProjectFinancials::class)->costBreakdown($record))
                    ->table([
                        TableColumn::make('Nhóm chi phí'),
                        TableColumn::make('Ngân sách'),
                        TableColumn::make('Đã cam kết'),
                        TableColumn::make('Thực tế'),
                        TableColumn::make('Còn lại'),
                        TableColumn::make('Tình trạng'),
                    ])
                    ->schema([
                        TextEntry::make('category')->hiddenLabel()->badge(),
                        $money('planned'),
                        $money('committed'),
                        $money('actual'),
                        $money('variance'),
                        TextEntry::make('status')->hiddenLabel()->badge(),
                    ])
                    ->placeholder('Chưa có ngân sách và chưa phát sinh chi phí. Thêm ngân sách ở tab "Ngân sách".'),
            ]);
    }

    /**
     * Lãi lỗ dự án (mục 33): doanh thu − các nhóm chi phí = lợi nhuận gộp.
     */
    private static function profitSection(): Section
    {
        return Section::make('Lãi lỗ dự án')
            ->icon(Heroicon::OutlinedPresentationChartLine)
            ->columns(4)
            ->columnSpanFull()
            ->schema([
                Columns::moneyEntry('contract_value', 'Doanh thu (trước thuế)')
                    ->weight(FontWeight::SemiBold),
                Columns::moneyEntry('labor_cost', 'Chi phí nhân sự')
                    ->helperText(fn (Project $record): string => 'Dự kiến theo giờ công việc: '
                        .Money::format(app(ProjectCostCollector::class)->estimatedLaborCost($record))),
                Columns::moneyEntry('supplier_cost', 'Chi phí nhà cung cấp'),
                Columns::moneyEntry('equipment_cost', 'Chi phí thiết bị'),
                Columns::moneyEntry('other_cost', 'Chi phí khác'),
                Columns::moneyEntry('actual_cost', 'Tổng chi phí thực tế'),
                Columns::moneyEntry('actual_profit', 'Lợi nhuận gộp')
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Project $record): string => (float) $record->actual_profit < 0 ? 'danger' : 'success'),
                TextEntry::make('profit_margin')
                    ->label('Biên lợi nhuận')
                    ->formatStateUsing(fn (mixed $state): string => Money::percent((string) $state))
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Project $record): string => static::marginColor($record->profit_margin)),
            ]);
    }

    private static function generalSection(): Section
    {
        return Section::make('Thông tin dự án')
            ->icon(Heroicon::OutlinedBriefcase)
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('code')
                    ->label('Mã dự án')
                    ->weight(FontWeight::SemiBold)
                    ->copyable(),
                TextEntry::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextEntry::make('manager.name')
                    ->label('Quản lý dự án')
                    ->placeholder('Chưa phân công'),
                TextEntry::make('customer.name')
                    ->label('Khách hàng')
                    ->url(fn (Project $record): ?string => $record->customer !== null && CustomerResource::canView($record->customer)
                        ? CustomerResource::getUrl('view', ['record' => $record->customer])
                        : null),
                TextEntry::make('contract.code')
                    ->label('Hợp đồng')
                    ->url(fn (Project $record): ?string => $record->contract !== null && ContractResource::canView($record->contract)
                        ? ContractResource::getUrl('view', ['record' => $record->contract])
                        : null),
                TextEntry::make('drive_url')
                    ->label('Google Drive')
                    ->formatStateUsing(fn (): string => 'Mở Google Drive')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('primary')
                    ->placeholder('Chưa có thư mục')
                    ->url(
                        fn (?string $state): ?string => str_starts_with((string) $state, 'https://') ? $state : null,
                        shouldOpenInNewTab: true,
                    ),
                TextEntry::make('start_date')
                    ->label('Ngày bắt đầu')
                    ->date()
                    ->placeholder('—'),
                TextEntry::make('end_date')
                    ->label('Hạn hoàn thành')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Project $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextEntry::make('creator.name')
                    ->label('Người tạo')
                    ->placeholder('—'),
                TextEntry::make('description')
                    ->label('Mô tả')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Màu theo biên lợi nhuận (mục 37): >30% tốt, 20–30% bình thường,
     * <20% cảnh báo, <10% nguy hiểm.
     */
    public static function marginColor(int|float|string|null $margin): string
    {
        return MarginBand::for($margin)->getColor();
    }
}
