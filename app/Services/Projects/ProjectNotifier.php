<?php

namespace App\Services\Projects;

use App\Enums\SystemRole;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Thông báo trong ứng dụng về dự án: vượt ngân sách, sắp tới hạn (mục 38).
 */
class ProjectNotifier
{
    public function overBudget(Project $project): void
    {
        $this->send(
            $project,
            'Dự án vượt ngân sách',
            "{$project->code} — {$project->name}: chi phí thực tế ".Money::format($project->actual_cost)
                .' đã vượt ngân sách '.Money::format($project->budget).'.',
            danger: true,
        );
    }

    public function deadlineApproaching(Project $project, int $daysLeft): void
    {
        $when = $daysLeft <= 0 ? 'hôm nay' : "còn {$daysLeft} ngày";

        $this->send(
            $project,
            'Dự án sắp tới hạn',
            "{$project->code} — {$project->name}: hạn hoàn thành {$project->end_date?->format('d/m/Y')} ({$when}), tiến độ {$project->progress}%.",
            danger: false,
        );
    }

    /**
     * Người nhận: quản lý dự án và những người giữ vai trò Giám đốc.
     *
     * @return Collection<int, User>
     */
    private function recipients(Project $project): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($project): void {
                $query
                    ->whereKey($project->project_manager_id ?? 0)
                    ->orWhereHas('roles', fn (Builder $query) => $query->where('name', SystemRole::Director->value));
            })
            ->get();
    }

    private function send(Project $project, string $title, string $body, bool $danger): void
    {
        $recipients = $this->recipients($project);

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-briefcase')
            ->actions([
                Action::make('view')
                    ->label('Xem dự án')
                    ->url(ProjectResource::getUrl('view', ['record' => $project])),
            ]);

        ($danger ? $notification->danger() : $notification->warning())->sendToDatabase($recipients);
    }
}
