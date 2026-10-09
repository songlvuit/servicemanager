<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Models\Project;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bảng Kanban của dự án (mục 27): 4 cột Cần làm – Đang làm – Chờ kiểm tra –
 * Hoàn thành; kéo thả thẻ để đổi trạng thái và thứ tự, bấm vào thẻ để sửa.
 */
class ProjectBoard extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.board';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(ProjectResource::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bảng công việc';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->code.' · '.$this->getRecord()->name;
    }

    /**
     * Công việc của từng cột. Người chỉ xem được việc của mình (Employee) thì
     * bảng chỉ có việc được giao cho họ.
     *
     * @return array<string, array{status: TaskStatus, tasks: Collection<int, Task>}>
     */
    public function getColumns(): array
    {
        $tasks = $this->getRecord()->tasks()
            ->with('assignee')
            ->visibleTo(auth()->user())
            ->whereIn('status', array_map(fn (TaskStatus $status): string => $status->value, TaskStatus::boardColumns()))
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
        $task = $this->getRecord()->tasks()->find($taskId);

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

            $position = 0;

            foreach ($orderedIds as $id) {
                // Chỉ sắp lại thẻ của đúng dự án và đúng cột này.
                Task::query()
                    ->whereKey((int) $id)
                    ->where('project_id', $this->getRecord()->getKey())
                    ->where('status', $target->value)
                    ->update(['sort_order' => ++$position]);
            }
        });
    }

    public function createTaskAction(): Action
    {
        return Action::make('createTask')
            ->label('Thêm công việc')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Thêm công việc')
            ->visible(fn (): bool => $this->canAddTasks())
            ->schema(TaskForm::components(withProject: false))
            ->action(function (array $data): void {
                $this->getRecord()->tasks()->create($data);

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
            ->fillForm(fn (array $arguments): array => $this->findTask($arguments)?->attributesToArray() ?? [])
            ->record(fn (array $arguments): ?Task => $this->findTask($arguments))
            ->schema(TaskForm::components(withProject: false))
            ->disabledForm(fn (array $arguments): bool => ! $this->canUpdate($this->findTask($arguments)))
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
            Action::make('backToProject')
                ->label('Về trang dự án')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            $this->createTaskAction(),
        ];
    }

    public function canAddTasks(): bool
    {
        $project = $this->getRecord();

        return $project instanceof Project
            && ! $project->trashed()
            && ! $project->isClosed()
            && (auth()->user()?->can('create', Task::class) ?? false);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findTask(array $arguments): ?Task
    {
        $task = $this->getRecord()->tasks()->find((int) ($arguments['task'] ?? 0));

        return $task !== null && (auth()->user()?->can('view', $task) ?? false) ? $task : null;
    }

    private function canUpdate(?Task $task): bool
    {
        return $task !== null && (auth()->user()?->can('update', $task) ?? false);
    }
}
