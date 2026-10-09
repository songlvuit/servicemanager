<?php

namespace App\Filament\Widgets;

use App\Enums\CostStatus;
use App\Enums\MarginBand;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Project;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Dự án cần chú ý" (mục 75): dự án đang mở mà vượt / sắp vượt ngân sách,
 * biên lợi nhuận thấp hoặc đã trễ hạn. Người không được xem chi phí nội bộ
 * chỉ thấy các dự án trễ hạn và không thấy các cột tiền.
 */
class AttentionProjects extends TableWidget
{
    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Access::can('projects.view');
    }

    public function table(Table $table): Table
    {
        $seesCosts = Access::canSeeCosts();

        return $table
            ->heading('Dự án cần chú ý')
            ->description($seesCosts
                ? 'Dự án đang mở vượt hoặc sắp vượt ngân sách, biên lợi nhuận thấp, hoặc đã trễ hạn.'
                : 'Dự án đang mở đã trễ hạn.')
            ->query(fn (): Builder => static::attentionQuery($seesCosts))
            ->defaultSort('profit_margin')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Dự án')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Project $record): string => $record->code.' · '.($record->customer?->name ?? '—'))
                    ->wrap(),
                TextColumn::make('reasons')
                    ->label('Vấn đề')
                    ->state(fn (Project $record): array => static::reasons($record, $seesCosts))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Vượt ngân sách', 'Trễ hạn', 'Lỗ' => 'danger',
                        default => 'warning',
                    }),
                Columns::money('budget', 'Ngân sách')
                    ->visible($seesCosts),
                Columns::money('actual_cost', 'Chi phí thực tế')
                    ->visible($seesCosts)
                    ->color(fn (Project $record): string => $record->costStatus()->getColor()),
                TextColumn::make('profit_margin')
                    ->label('Biên LN')
                    ->visible($seesCosts)
                    ->formatStateUsing(fn (mixed $state): string => Money::percent(is_scalar($state) ? $state : 0))
                    ->badge()
                    // Chưa phát sinh chi phí thì biên 100% chưa nói lên điều gì.
                    ->color(fn (Project $record): string => Decimal::isPositive($record->actual_cost)
                        ? MarginBand::for($record->profit_margin)->getColor()
                        : 'gray')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Hạn hoàn thành')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (Project $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Không có dự án nào cần chú ý')
            ->emptyStateDescription('Mọi dự án đang mở đều trong ngân sách và đúng hạn.');
    }

    /**
     * @return Builder<Project>
     */
    public static function attentionQuery(bool $seesCosts): Builder
    {
        return Project::query()
            ->with('customer')
            ->visibleTo(auth()->user())
            ->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value])
            ->where(function (Builder $query) use ($seesCosts): void {
                $query->whereDate('end_date', '<', today());

                if (! $seesCosts) {
                    return;
                }

                $query
                    // Vượt ngân sách (Rule 7).
                    ->orWhereColumn('actual_cost', '>', 'budget')
                    // Đạt ngưỡng cảnh báo ngân sách (Rule 6).
                    ->orWhere(fn (Builder $query) => $query
                        ->where('budget', '>', 0)
                        ->whereRaw('actual_cost * 100 >= budget * ?', [(float) config('project.budget_warning_threshold', 80)]))
                    // Biên lợi nhuận thấp, chỉ xét khi đã phát sinh chi phí.
                    ->orWhere(fn (Builder $query) => $query
                        ->where('contract_value', '>', 0)
                        ->where('actual_cost', '>', 0)
                        ->where('profit_margin', '<', (float) config('project.margin_warning', 20)));
            });
    }

    /**
     * @return list<string>
     */
    public static function reasons(Project $project, bool $seesCosts): array
    {
        $reasons = [];

        if ($seesCosts) {
            $costStatus = $project->costStatus();

            if ($costStatus === CostStatus::Over) {
                $reasons[] = 'Vượt ngân sách';
            } elseif ($costStatus === CostStatus::Warning) {
                $reasons[] = 'Sắp vượt ngân sách';
            }

            if (Decimal::isPositive($project->contract_value) && Decimal::isPositive($project->actual_cost)) {
                if ((float) $project->actual_profit < 0) {
                    $reasons[] = 'Lỗ';
                } elseif (in_array(MarginBand::for($project->profit_margin), [MarginBand::Warning, MarginBand::Danger], true)) {
                    $reasons[] = 'Biên lợi nhuận thấp';
                }
            }
        }

        if ($project->isOverdue()) {
            $reasons[] = 'Trễ hạn';
        }

        return $reasons;
    }
}
