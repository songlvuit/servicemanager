<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Enums\ProjectStatus;
use App\Filament\Support\WorkflowAction;
use App\Models\Project;
use App\Services\Projects\ProjectWorkflow;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Các nút đổi trạng thái dự án (mục 16).
 */
class ProjectActions
{
    public static function start(): Action
    {
        return static::step('start', 'Bắt đầu triển khai', ProjectStatus::Active, [ProjectStatus::Planning])
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->modalDescription('Dự án chuyển sang "Đang thực hiện".');
    }

    public static function hold(): Action
    {
        return static::step('hold', 'Tạm dừng', ProjectStatus::OnHold)
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->modalDescription('Dự án tạm dừng; có thể tiếp tục lại bất cứ lúc nào.');
    }

    public static function resume(): Action
    {
        return static::step('resume', 'Tiếp tục', ProjectStatus::Active, [ProjectStatus::OnHold])
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->modalDescription('Dự án trở lại "Đang thực hiện".');
    }

    public static function complete(): Action
    {
        return static::step('complete', 'Hoàn thành', ProjectStatus::Completed)
            ->icon(Heroicon::OutlinedFlag)
            ->color('info')
            ->modalDescription('Dự án đã hoàn thành sẽ khoá ngân sách và không nhận thêm chi phí mới.');
    }

    public static function reopen(): Action
    {
        return static::step('reopen', 'Mở lại', ProjectStatus::Active, [ProjectStatus::Completed])
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalDescription('Mở lại dự án để ghi nhận thêm công việc hoặc chi phí.');
    }

    public static function cancel(): Action
    {
        return static::step('cancel', 'Huỷ dự án', ProjectStatus::Cancelled)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalDescription('Dự án đã huỷ không mở lại được. Thao tác này không hoàn tác được.');
    }

    /**
     * @param  list<ProjectStatus>  $from
     */
    private static function step(string $name, string $label, ProjectStatus $target, array $from = []): Action
    {
        return WorkflowAction::make($name, $label, $target, $from)
            ->modalHeading(fn (Project $record): string => "{$label}: {$record->code}?")
            ->action(fn (Project $record) => WorkflowAction::run(
                $record,
                static::call($name, $record),
                "Dự án đã chuyển sang \"{$target->getLabel()}\".",
            ));
    }

    private static function call(string $step, Project $record): Closure
    {
        return fn () => app(ProjectWorkflow::class)->{$step}($record, auth()->user());
    }
}
