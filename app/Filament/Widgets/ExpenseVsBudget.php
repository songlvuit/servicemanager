<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Models\Project;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

/**
 * Chi phí so với ngân sách của các dự án đang thực hiện (mục 75).
 */
class ExpenseVsBudget extends ChartWidget
{
    private const LIMIT = 8;

    protected static ?int $sort = 30;

    protected ?string $heading = 'Chi phí so với ngân sách';

    protected ?string $description = 'Các dự án đang thực hiện có ngân sách lớn nhất.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có dự án đang thực hiện';

    protected ?string $emptyStateDescription = 'Biểu đồ so sánh ngân sách và chi phí thực tế của từng dự án đang chạy.';

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
            ->running()
            ->orderByDesc('budget')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'budget', 'actual_cost']);

        if ($projects->isEmpty()) {
            return [];
        }

        [$budgetColor, $actualColor] = ChartPalette::series(2);

        return [
            'labels' => $projects->map(fn (Project $project): string => Str::limit($project->name, 26))->all(),
            'datasets' => [
                [
                    'label' => 'Ngân sách',
                    'data' => $projects->map(fn (Project $project): float => (float) $project->budget)->all(),
                    'backgroundColor' => $budgetColor,
                    'borderWidth' => 0,
                    'borderRadius' => 4,
                    'maxBarThickness' => 14,
                ],
                [
                    'label' => 'Chi phí thực tế',
                    'data' => $projects->map(fn (Project $project): float => (float) $project->actual_cost)->all(),
                    'backgroundColor' => $actualColor,
                    'borderWidth' => 0,
                    'borderRadius' => 4,
                    'maxBarThickness' => 14,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::moneyAxis(legend: true, horizontal: true);
    }
}
