<?php

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Models\Project;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

/**
 * Lợi nhuận thực tế của từng dự án (mục 35): giá trị hợp đồng − chi phí thực tế.
 */
class ProjectProfitabilityChart extends ChartWidget
{
    private const LIMIT = 8;

    protected static ?int $sort = 31;

    protected ?string $heading = 'Lợi nhuận theo dự án';

    protected ?string $description = 'Giá trị hợp đồng − chi phí thực tế, các dự án đang thực hiện và đã hoàn thành có giá trị lớn nhất.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có dự án';

    public static function canView(): bool
    {
        return Access::can('projects.view') && Access::canSeeCosts();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $projects = Project::query()
            ->visibleTo(auth()->user())
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::Completed->value])
            ->orderByDesc('contract_value')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'actual_profit'])
            ->sortByDesc(fn (Project $project): float => (float) $project->actual_profit)
            ->values();

        if ($projects->isEmpty()) {
            return [];
        }

        return [
            'labels' => $projects->map(fn (Project $project): string => Str::limit($project->name, 26))->all(),
            'datasets' => [[
                'label' => 'Lợi nhuận thực tế',
                'data' => $projects->map(fn (Project $project): float => (float) $project->actual_profit)->all(),
                'backgroundColor' => ChartPalette::PRIMARY,
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 18,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis(horizontal: true);
    }
}
