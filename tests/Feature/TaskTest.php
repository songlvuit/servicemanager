<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\Pages\ProjectBoard;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use BuildsProjects;
    use RefreshDatabase;

    private User $pm;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
        $this->employee = $this->createUserWithRole(SystemRole::Employee);
    }

    public function test_project_progress_is_the_average_of_its_active_tasks(): void
    {
        $project = $this->makeProject();

        $first = Task::factory()->create(['project_id' => $project->getKey(), 'progress' => 50]);
        Task::factory()->create(['project_id' => $project->getKey(), 'progress' => 0]);
        $cancelled = Task::factory()->create(['project_id' => $project->getKey(), 'status' => TaskStatus::Cancelled, 'progress' => 0]);

        // (50 + 0) / 2 — công việc đã huỷ không tính.
        $this->assertSame(25, $project->refresh()->progress);

        // Hoàn thành thì tiến độ công việc tự là 100%.
        $first->update(['status' => TaskStatus::Done]);

        $this->assertSame(100, $first->refresh()->progress);
        $this->assertSame(50, $project->refresh()->progress);

        $cancelled->delete();
        Task::query()->where('project_id', $project->getKey())->where('progress', 0)->first()->delete();

        $this->assertSame(100, $project->refresh()->progress);
    }

    public function test_employee_sees_and_updates_only_assigned_tasks(): void
    {
        $project = $this->makeProject();
        $mine = Task::factory()->create(['project_id' => $project->getKey(), 'assignee_id' => $this->employee->getKey(), 'name' => 'Việc của tôi ABC']);
        $other = Task::factory()->create(['project_id' => $project->getKey(), 'assignee_id' => $this->pm->getKey(), 'name' => 'Việc của người khác XYZ']);

        $this->assertTrue($this->employee->can('update', $mine));
        $this->assertFalse($this->employee->can('view', $other));
        $this->assertFalse($this->employee->can('update', $other));
        $this->assertFalse($this->employee->can('create', Task::class));
        $this->assertFalse($this->employee->can('delete', $mine));

        $this->actingAs($this->employee);

        Livewire::test(ManageTasks::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            // Nhân viên chỉ đổi được trạng thái / tiến độ: tên công việc bị khoá.
            ->callAction(TestAction::make('edit')->table($mine), [
                'name' => 'Tên bị đổi',
                'status' => TaskStatus::InProgress->value,
                'progress' => 30,
            ])
            ->assertHasNoFormErrors();

        $mine->refresh();

        $this->assertSame('Việc của tôi ABC', $mine->name);
        $this->assertSame(TaskStatus::InProgress, $mine->status);
        $this->assertSame(30, $mine->progress);
    }

    public function test_pm_creates_a_task_from_the_task_screen(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->pm);

        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'project_id' => $project->getKey(),
                'name' => 'Viết kịch bản',
                'assignee_id' => $this->employee->getKey(),
                'due_date' => today()->addWeek()->toDateString(),
                'estimated_hours' => 12,
            ])
            ->assertHasNoFormErrors();

        $task = Task::query()->where('name', 'Viết kịch bản')->first();

        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame($this->employee->getKey(), $task->assignee_id);
        $this->assertSame('12.00', $task->estimated_hours);
    }

    public function test_dragging_a_card_on_the_board_changes_status_and_order(): void
    {
        $project = $this->makeProject();
        $a = Task::factory()->create(['project_id' => $project->getKey(), 'name' => 'Thẻ A']);
        $b = Task::factory()->create(['project_id' => $project->getKey(), 'name' => 'Thẻ B', 'status' => TaskStatus::InProgress]);
        $c = Task::factory()->create(['project_id' => $project->getKey(), 'name' => 'Thẻ C', 'status' => TaskStatus::InProgress]);

        $this->actingAs($this->pm);

        // Kéo thẻ A sang cột "Đang làm", thả vào giữa C và B.
        Livewire::test(ProjectBoard::class, ['record' => $project->getRouteKey()])
            ->assertSee('Thẻ A')
            ->call('moveTask', $a->getKey(), TaskStatus::InProgress->value, [$c->getKey(), $a->getKey(), $b->getKey()]);

        $this->assertSame(TaskStatus::InProgress, $a->refresh()->status);
        $this->assertSame(
            [$c->getKey(), $a->getKey(), $b->getKey()],
            Task::query()->where('status', TaskStatus::InProgress->value)->orderBy('sort_order')->pluck('id')->all(),
        );

        // Thả vào "Hoàn thành" thì tiến độ thành 100%.
        Livewire::test(ProjectBoard::class, ['record' => $project->getRouteKey()])
            ->call('moveTask', $a->getKey(), TaskStatus::Done->value, [$a->getKey()]);

        $this->assertSame(100, $a->refresh()->progress);
    }

    public function test_board_ignores_moves_the_user_is_not_allowed_to_make(): void
    {
        $project = $this->makeProject();
        $project->memberships()->create(['user_id' => $this->employee->getKey()]);
        $notMine = Task::factory()->create(['project_id' => $project->getKey(), 'assignee_id' => $this->pm->getKey()]);
        $foreign = Task::factory()->create(); // công việc của dự án khác

        $this->actingAs($this->employee);

        Livewire::test(ProjectBoard::class, ['record' => $project->getRouteKey()])
            ->call('moveTask', $notMine->getKey(), TaskStatus::Done->value, [])
            ->call('moveTask', $foreign->getKey(), TaskStatus::Done->value, [])
            ->call('moveTask', $notMine->getKey(), 'khong-hop-le', []);

        $this->assertSame(TaskStatus::Todo, $notMine->refresh()->status);
        $this->assertSame(TaskStatus::Todo, $foreign->refresh()->status);
    }

    public function test_task_card_can_be_edited_from_the_board(): void
    {
        $project = $this->makeProject();
        $task = Task::factory()->create(['project_id' => $project->getKey(), 'name' => 'Dựng phim']);

        $this->actingAs($this->pm);

        Livewire::test(ProjectBoard::class, ['record' => $project->getRouteKey()])
            ->callAction(TestAction::make('editTask')->arguments(['task' => $task->getKey()]), [
                'name' => 'Dựng phim bản 2',
                'status' => TaskStatus::Review->value,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('Dựng phim bản 2', $task->refresh()->name);
        $this->assertSame(TaskStatus::Review, $task->status);
    }

    public function test_overdue_tasks_are_flagged(): void
    {
        $late = Task::factory()->create(['due_date' => today()->subDay()]);
        $doneLate = Task::factory()->create(['due_date' => today()->subDay(), 'status' => TaskStatus::Done]);
        $onTime = Task::factory()->create(['due_date' => today()]);

        $this->assertTrue($late->isOverdue());
        $this->assertFalse($doneLate->isOverdue());
        $this->assertFalse($onTime->isOverdue());
        $this->assertSame([$late->getKey()], Task::query()->overdue()->pluck('id')->all());
    }

    public function test_task_screen_requires_permission(): void
    {
        $this->actingAs($this->pm)->get(TaskResource::getUrl('index'))->assertOk();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(TaskResource::getUrl('index'))
            ->assertForbidden();
    }
}
