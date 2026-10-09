<?php

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Models\Project;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Số dự án theo trạng thái (mục 35).
 */
class ProjectStatusChart extends ChartWidget
{
    protected static ?int $sort = 33;

    protected ?string $heading = 'Dự án theo trạng thái';

    protected ?string $description = 'Số dự án bạn được xem, theo trạng thái hiện tại.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có dự án nào';

    public static function canView(): bool
    {
        return Access::can('projects.view');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Project::query()
            ->visibleTo(auth()->user())
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            return [];
        }

        // Mỗi trạng thái giữ một màu cố định, kể cả khi trạng thái khác không có dự án.
        $colors = array_combine(
            array_map(fn (ProjectStatus $status): string => $status->value, ProjectStatus::cases()),
            ChartPalette::series(count(ProjectStatus::cases())),
        );

        $labels = [];
        $data = [];
        $background = [];

        foreach (ProjectStatus::cases() as $status) {
            $total = (int) ($counts[$status->value] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $status->getLabel();
            $data[] = $total;
            $background[] = $colors[$status->value];
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Số dự án',
                'data' => $data,
                'backgroundColor' => $background,
                'borderWidth' => 2,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartPalette::doughnut(money: false);
    }
}
