<?php

namespace App\Services\Tasks;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Thông báo trong ứng dụng về tiền của công việc (mục 38).
 */
class TaskNotifier
{
    public function overBudget(Task $task): void
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Công việc vượt ngân sách')
            ->body("{$task->name}: chi phí thực tế ".Money::format($task->actual_cost)
                .' đã vượt ngân sách '.Money::format($task->budget).'.')
            ->icon('heroicon-o-banknotes')
            ->danger()
            ->actions([
                Action::make('view')
                    ->label('Xem công việc')
                    ->url(TaskResource::getUrl('view', ['record' => $task])),
            ])
            ->sendToDatabase($recipients);
    }

    /**
     * Ngân sách là thông tin nội bộ: chỉ báo cho người vừa xem được mọi công
     * việc vừa xem được chi phí nội bộ (mặc định: Giám đốc, Quản lý công việc).
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn (Builder $query) => $query->where('name', 'internal_costs.view'))
            ->whereHas('roles.permissions', fn (Builder $query) => $query->where('name', 'tasks.view_all'))
            ->get();
    }
}
