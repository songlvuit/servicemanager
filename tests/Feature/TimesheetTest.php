<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\Timesheets\Pages\ManageTimesheets;
use App\Filament\Resources\Timesheets\TimesheetResource;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use BuildsProjects;
    use RefreshDatabase;

    private User $employee;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->employee = $this->createUserWithRole(SystemRole::Employee, ['hourly_rate' => 100000]);
        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
    }

    public function test_labor_cost_is_hours_times_the_rate_at_the_time_of_logging(): void
    {
        $project = $this->makeProject();

        $entry = Timesheet::factory()->create([
            'project_id' => $project->getKey(),
            'user_id' => $this->employee->getKey(),
            'hours' => 7.5,
        ]);

        $this->assertSame('100000.00', $entry->hourly_rate);
        $this->assertSame('750000.00', $entry->cost);
        $this->assertSame('750000.00', $project->refresh()->labor_cost);

        // Tăng đơn giá về sau không làm đổi chi phí đã ghi nhận.
        $this->employee->update(['hourly_rate' => 200000]);
        $entry->update(['description' => 'Bổ sung mô tả']);

        $this->assertSame('750000.00', $entry->refresh()->cost);

        // Sửa số giờ thì tính lại theo đơn giá đã chụp.
        $entry->update(['hours' => 8]);

        $this->assertSame('800000.00', $entry->refresh()->cost);
        $this->assertSame('800000.00', $project->refresh()->labor_cost);

        $entry->delete();

        $this->assertSame('0.00', $project->refresh()->labor_cost);
    }

    public function test_task_actual_hours_follow_its_timesheets(): void
    {
        $project = $this->makeProject();
        $task = Task::factory()->create(['project_id' => $project->getKey()]);
        $otherTask = Task::factory()->create(['project_id' => $project->getKey()]);

        $first = Timesheet::factory()->create(['project_id' => $project->getKey(), 'task_id' => $task->getKey(), 'hours' => 3]);
        Timesheet::factory()->create(['project_id' => $project->getKey(), 'task_id' => $task->getKey(), 'hours' => 4.5]);

        $this->assertSame('7.50', $task->refresh()->actual_hours);

        // Chuyển một dòng sang công việc khác: cả hai công việc được cộng lại.
        $first->update(['task_id' => $otherTask->getKey()]);

        $this->assertSame('4.50', $task->refresh()->actual_hours);
        $this->assertSame('3.00', $otherTask->refresh()->actual_hours);
    }

    public function test_employee_logs_and_sees_only_own_time(): void
    {
        $project = $this->makeProject();
        $project->memberships()->create(['user_id' => $this->employee->getKey()]);
        $others = Timesheet::factory()->create(['project_id' => $project->getKey(), 'user_id' => $this->pm->getKey()]);

        $this->actingAs($this->employee);

        Livewire::test(ManageTimesheets::class)
            ->assertCanNotSeeTableRecords([$others])
            ->callAction('create', data: [
                'project_id' => $project->getKey(),
                'work_date' => today()->toDateString(),
                'hours' => 6,
                'description' => 'Dựng phim',
            ])
            ->assertHasNoFormErrors();

        $mine = Timesheet::query()->where('user_id', $this->employee->getKey())->first();

        $this->assertNotNull($mine);
        $this->assertSame('600000.00', $mine->cost);
        $this->assertTrue($this->employee->can('update', $mine));
        $this->assertFalse($this->employee->can('view', $others));

        // Chi phí nhân sự là thông tin nội bộ: nhân viên không thấy cột này.
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertDontSee('Chi phí nhân sự');

        // Quản lý dự án thấy giờ công của mọi người và cả chi phí.
        $this->actingAs($this->pm);

        $this->assertSame(2, Timesheet::query()->visibleTo($this->pm)->count());
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertSee('Chi phí nhân sự');
    }

    public function test_hours_must_be_within_a_day(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->pm);

        Livewire::test(ManageTimesheets::class)
            ->callAction('create', data: [
                'user_id' => $this->employee->getKey(),
                'project_id' => $project->getKey(),
                'work_date' => today()->addDay()->toDateString(),
                'hours' => 25,
            ])
            ->assertHasFormErrors(['hours', 'work_date']);
    }
}
