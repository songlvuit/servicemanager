<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\TaskBoard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTest extends TestCase
{
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

    public function test_finishing_a_task_sets_progress_and_new_tasks_go_to_the_end_of_their_column(): void
    {
        $first = Task::factory()->create(['progress' => 50]);
        $second = Task::factory()->create();

        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(2, $second->refresh()->sort_order);

        // Hoàn thành thì tiến độ công việc tự là 100%.
        $first->update(['status' => TaskStatus::Done]);

        $this->assertSame(100, $first->refresh()->progress);
    }

    public function test_employee_sees_and_updates_only_assigned_tasks(): void
    {
        $mine = Task::factory()->create(['assignee_id' => $this->employee->getKey(), 'name' => 'Việc của tôi ABC']);
        $other = Task::factory()->create(['assignee_id' => $this->pm->getKey(), 'name' => 'Việc của người khác XYZ']);

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
        $this->actingAs($this->pm);

        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
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
        $this->assertNull($task->contract_id);
    }

    public function test_dragging_a_card_on_the_board_changes_status_and_order(): void
    {
        $a = Task::factory()->create(['name' => 'Thẻ A']);
        $b = Task::factory()->create(['name' => 'Thẻ B', 'status' => TaskStatus::InProgress]);
        $c = Task::factory()->create(['name' => 'Thẻ C', 'status' => TaskStatus::InProgress]);

        $this->actingAs($this->pm);

        $this->get(TaskResource::getUrl('board'))->assertOk()->assertSee('Thẻ A');

        // Kéo thẻ A sang cột "Đang làm", thả vào giữa C và B.
        Livewire::test(TaskBoard::class)
            ->assertSee('Thẻ A')
            ->call('moveTask', $a->getKey(), TaskStatus::InProgress->value, [$c->getKey(), $a->getKey(), $b->getKey()]);

        $this->assertSame(TaskStatus::InProgress, $a->refresh()->status);
        $this->assertSame(
            [$c->getKey(), $a->getKey(), $b->getKey()],
            Task::query()->where('status', TaskStatus::InProgress->value)->orderBy('sort_order')->pluck('id')->all(),
        );

        // Thả vào "Hoàn thành" thì tiến độ thành 100%.
        Livewire::test(TaskBoard::class)
            ->call('moveTask', $a->getKey(), TaskStatus::Done->value, [$a->getKey()]);

        $this->assertSame(100, $a->refresh()->progress);
    }

    public function test_board_shows_only_visible_tasks_and_ignores_moves_that_are_not_allowed(): void
    {
        $mine = Task::factory()->create(['assignee_id' => $this->employee->getKey(), 'name' => 'Thẻ của tôi']);
        $notMine = Task::factory()->create(['assignee_id' => $this->pm->getKey(), 'name' => 'Thẻ của quản lý']);
        // Việc hoàn thành đã lâu không còn nằm trên bảng.
        $old = Task::factory()->create(['assignee_id' => $this->employee->getKey(), 'name' => 'Thẻ đã xong từ lâu', 'status' => TaskStatus::Done]);
        Task::query()->whereKey($old->getKey())->update(['updated_at' => now()->subDays(30)]);

        $this->actingAs($this->employee);

        Livewire::test(TaskBoard::class)
            ->assertSee('Thẻ của tôi')
            ->assertDontSee('Thẻ của quản lý')
            ->assertDontSee('Thẻ đã xong từ lâu')
            ->call('moveTask', $notMine->getKey(), TaskStatus::Done->value, [])
            ->call('moveTask', $mine->getKey(), 'khong-hop-le', [])
            ->call('moveTask', $mine->getKey(), TaskStatus::Cancelled->value, []);

        $this->assertSame(TaskStatus::Todo, $notMine->refresh()->status);
        $this->assertSame(TaskStatus::Todo, $mine->refresh()->status);

        // Quản lý lọc "Việc tôi phụ trách".
        $this->actingAs($this->pm);

        Livewire::test(TaskBoard::class)
            ->assertSee('Thẻ của tôi')
            ->assertSee('Thẻ của quản lý')
            ->call('setScope', 'mine')
            ->assertDontSee('Thẻ của tôi')
            ->assertSee('Thẻ của quản lý');
    }

    public function test_task_card_can_be_edited_from_the_board(): void
    {
        $task = Task::factory()->create(['name' => 'Dựng phim']);

        $this->actingAs($this->pm);

        Livewire::test(TaskBoard::class)
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
