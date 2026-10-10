<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Bảng Kanban của công việc (mục 27): 4 cột Cần làm – Đang làm – Chờ kiểm tra –
 * Hoàn thành; kéo thả thẻ để đổi trạng thái và thứ tự, bấm vào thẻ để sửa.
 *
 * Cột "Hoàn thành" chỉ giữ việc xong trong DONE_DAYS ngày gần đây để bảng không
 * dài mãi. Người chỉ xem được việc của mình thì bảng chỉ có việc của họ.
 */
class TaskBoard extends Page
{
    /** Việc đã hoàn thành quá số ngày này không còn hiện trên bảng. */
    public const DONE_DAYS = 14;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.board';

    /** 'all' = mọi việc được xem; 'mine' = việc mình phụ trách. */
    #[Url(as: 'scope')]
    public string $scope = 'all';

    public function mount(): void
    {
        abort_unless(TaskResource::canViewAny(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bảng công việc';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Kéo thả thẻ để đổi trạng thái. Việc đã hoàn thành quá '.self::DONE_DAYS.' ngày không còn hiện ở đây.';
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'mine' ? 'mine' : 'all';
    }

    /**
     * Công việc của từng cột.
     *
     * @return array<string, array{status: TaskStatus, tasks: Collection<int, Task>}>
     */
    public function getColumns(): array
    {
        $tasks = $this->tasks()
            ->with(['assignee', 'customer'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Task $task): string => $task->status->value);

        $columns = [];

        foreach (TaskStatus::boardColumns() as $status) {
            $columns[$status->value] = [
                'status' => $status,
                'tasks' => $tasks->get($status->value, new Collection),
            ];
        }

        return $columns;
    }

    /**
     * Gọi từ giao diện khi thả một thẻ: chuyển trạng thái và lưu lại thứ tự của cột đích.
     *
     * @param  list<int|string>  $orderedIds  Thứ tự thẻ trong cột đích sau khi thả.
     */
    public function moveTask(int $taskId, string $status, array $orderedIds = []): void
    {
        $target = TaskStatus::tryFrom($status);
        $task = $this->tasks()->find($taskId);

        if ($task === null || $target === null || ! in_array($target, TaskStatus::boardColumns(), true)) {
            return;
        }

        if (auth()->user()->cannot('update', $task)) {
            Notification::make()
                ->title('Bạn không có quyền cập nhật công việc này.')
                ->danger()
                ->send();

            return;
        }

        DB::transaction(function () use ($task, $target, $orderedIds): void {
            $task->update(['status' => $target]);

            $visible = $this->tasks()->where('status', $target->value)->pluck('id')->all();
            $position = 0;

            foreach ($orderedIds as $id) {
                // Chỉ sắp lại những thẻ người này thấy trong đúng cột này.
                if (in_array((int) $id, $visible, true)) {
                    Task::query()->whereKey((int) $id)->update(['sort_order' => ++$position]);
                }
            }
        });
    }

    public function createTaskAction(): Action
    {
        return Action::make('createTask')
            ->label('Thêm công việc')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Thêm công việc')
            ->visible(fn (): bool => auth()->user()?->can('create', Task::class) ?? false)
            ->schema(TaskForm::components())
            ->action(function (array $data): void {
                Task::create($data);

                Notification::make()->title('Đã thêm công việc.')->success()->send();
            });
    }

    /**
     * Mở từ thẻ công việc: mountAction('editTask', { task: ID }).
     */
    public function editTaskAction(): Action
    {
        return Action::make('editTask')
            ->modalHeading('Cập nhật công việc')
            ->modalSubmitActionLabel('Lưu')
            ->fillForm(fn (array $arguments): array => TaskForm::state($this->findTask($arguments)?->attributesToArray() ?? []))
            ->record(fn (array $arguments): ?Task => $this->findTask($arguments))
            ->schema(TaskForm::components())
            ->disabledForm(fn (array $arguments): bool => ! $this->canUpdate($this->findTask($arguments)))
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('open')
                    ->label('Mở trang công việc')
                    ->color('gray')
                    ->url(($task = $this->findTask($arguments)) ? TaskResource::getUrl('view', ['record' => $task]) : null),
            ])
            ->action(function (array $data, array $arguments): void {
                $task = $this->findTask($arguments);

                if ($task === null || ! $this->canUpdate($task)) {
                    Notification::make()->title('Bạn không có quyền cập nhật công việc này.')->danger()->send();

                    return;
                }

                $task->update($data);

                Notification::make()->title('Đã lưu công việc.')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('Dạng danh sách')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(fn (): string => TaskResource::getUrl('index')),
            $this->createTaskAction(),
        ];
    }

    /**
     * Việc người dùng được xem và còn nằm trên bảng.
     *
     * @return Builder<Task>
     */
    private function tasks(): Builder
    {
        return Task::query()
            ->visibleTo(auth()->user())
            ->when($this->scope === 'mine', fn (Builder $query) => $query->where('assignee_id', auth()->id()))
            ->whereIn('status', array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::boardColumns()))
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', TaskStatus::Done->value)
                ->orWhere('updated_at', '>=', now()->subDays(self::DONE_DAYS)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findTask(array $arguments): ?Task
    {
        $task = Task::query()->visibleTo(auth()->user())->find((int) ($arguments['task'] ?? 0));

        return $task !== null && (auth()->user()?->can('view', $task) ?? false) ? $task : null;
    }

    private function canUpdate(?Task $task): bool
    {
        return $task !== null && (auth()->user()?->can('update', $task) ?? false);
    }
}
