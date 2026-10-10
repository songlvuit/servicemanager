<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Support\Access;
use App\Filament\Support\ChartPalette;
use App\Models\Task;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Số công việc theo trạng thái (mục 35).
 */
class TaskStatusChart extends ChartWidget
{
    protected static ?int $sort = 33;

    protected ?string $heading = 'Công việc theo trạng thái';

    protected ?string $description = 'Số công việc bạn được xem, theo trạng thái hiện tại.';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Chưa có công việc nào';

    public static function canView(): bool
    {
        return Access::can('tasks.view');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Task::query()
            ->visibleTo(auth()->user())
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            return [];
        }

        // Mỗi trạng thái giữ một màu cố định, kể cả khi trạng thái khác không có công việc.
        $colors = array_combine(
            array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::cases()),
            ChartPalette::series(count(TaskStatus::cases())),
        );

        $labels = [];
        $data = [];
        $background = [];

        foreach (TaskStatus::cases() as $status) {
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
                'label' => 'Số công việc',
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
