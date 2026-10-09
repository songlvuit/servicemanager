<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use BuildsProjects;
    use RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
    }

    public function test_project_cannot_be_created_from_a_contract_that_is_not_active(): void
    {
        $draft = Contract::factory()->create();

        try {
            Project::factory()->create(['contract_id' => $draft->getKey()]);
            $this->fail('Rule 1: hợp đồng chưa hiệu lực thì không tạo được dự án.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('hợp đồng đang hiệu lực', $exception->getMessage());
        }

        $this->assertSame(0, Project::query()->count());

        // Form cũng từ chối, kèm thông báo rõ ràng.
        $this->actingAs($this->pm);

        Livewire::test(CreateProject::class)
            ->fillForm(['contract_id' => $draft->getKey(), 'name' => 'Dự án thử', 'contract_value' => 1000000])
            ->call('create')
            ->assertHasFormErrors(['contract_id']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_pm_creates_a_project_from_an_active_contract(): void
    {
        $contract = $this->makeActiveContract(['contract_value' => 80000000]);

        $this->actingAs($this->pm);

        Livewire::withQueryParams(['contract' => $contract->getKey()])
            ->test(CreateProject::class)
            ->assertSchemaStateSet(['contract_id' => $contract->getKey(), 'contract_value' => '80000000'])
            ->fillForm(['name' => 'TVC mùa hè', 'end_date' => today()->addMonth()->toDateString()])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->first();

        $this->assertNotNull($project);
        $this->assertSame('PRJ-'.now()->year.'-0001', $project->code);
        $this->assertSame($contract->customer_id, $project->customer_id);
        $this->assertSame(ProjectStatus::Planning, $project->status);
        $this->assertSame('80000000.00', $project->contract_value);
        // Chưa có ngân sách và chi phí: lợi nhuận bằng doanh thu.
        $this->assertSame('80000000.00', $project->expected_profit);
        $this->assertSame('100.00', $project->profit_margin);
    }

    public function test_project_status_follows_the_workflow(): void
    {
        $project = $this->makeProject();
        $workflow = app(ProjectWorkflow::class);

        $project = $workflow->start($project, $this->pm);
        $this->assertSame(ProjectStatus::Active, $project->status);

        $project = $workflow->hold($project, $this->pm);
        $this->assertSame(ProjectStatus::OnHold, $project->status);

        $project = $workflow->resume($project, $this->pm);
        $project = $workflow->complete($project, $this->pm);
        $this->assertSame(ProjectStatus::Completed, $project->status);
        $this->assertTrue($project->isClosed());

        $project = $workflow->reopen($project, $this->pm);
        $project = $workflow->cancel($project, $this->pm);
        $this->assertSame(ProjectStatus::Cancelled, $project->status);

        $this->expectException(WorkflowException::class);

        // Dự án đã huỷ không mở lại được.
        $workflow->start($project, $this->pm);
    }

    public function test_planning_project_cannot_be_completed_directly(): void
    {
        $project = $this->makeProject();

        $this->expectException(WorkflowException::class);

        app(ProjectWorkflow::class)->complete($project, $this->pm);
    }

    public function test_employee_only_sees_projects_they_take_part_in(): void
    {
        $employee = $this->createUserWithRole(SystemRole::Employee);

        $member = $this->makeProject(['name' => 'Dự án là thành viên']);
        $member->memberships()->create(['user_id' => $employee->getKey()]);

        $assigned = $this->makeProject(['name' => 'Dự án có việc được giao']);
        Task::factory()->create(['project_id' => $assigned->getKey(), 'assignee_id' => $employee->getKey()]);

        $other = $this->makeProject(['name' => 'Dự án không liên quan']);

        $visible = Project::query()->visibleTo($employee)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$member->getKey(), $assigned->getKey()], $visible);
        $this->assertTrue($employee->can('view', $member));
        $this->assertFalse($employee->can('view', $other));
        $this->assertFalse($employee->can('update', $member));

        $this->actingAs($employee);

        $this->get(ProjectResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Dự án là thành viên')
            ->assertSee('Dự án có việc được giao')
            ->assertDontSee('Dự án không liên quan');

        $this->get(ProjectResource::getUrl('view', ['record' => $member]))->assertOk();
        $this->get(ProjectResource::getUrl('view', ['record' => $other]))->assertNotFound();

        // Quản lý dự án (có quyền xem tất cả) thấy cả ba.
        $this->assertSame(3, Project::query()->visibleTo($this->pm)->count());
    }

    public function test_costs_and_profit_are_hidden_from_users_without_cost_access(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee('Doanh thu')
            ->assertDontSee('Lợi nhuận thực tế')
            ->assertDontSee('Kiểm soát chi phí');

        $this->actingAs($this->pm)
            ->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee('Lợi nhuận thực tế')
            ->assertSee('Kiểm soát chi phí');
    }

    public function test_only_planning_projects_without_costs_can_be_deleted(): void
    {
        $planning = $this->makeProject();
        $active = app(ProjectWorkflow::class)->start($this->makeProject(), $this->pm);

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->assertTrue(ProjectResource::canDelete($planning));
        $this->assertFalse(ProjectResource::canDelete($active));
    }

    public function test_workflow_buttons_and_pages_work_for_the_project_manager(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->pm);

        $this->get(ProjectResource::getUrl('index'))->assertOk()->assertSee($project->code);
        $this->get(ProjectResource::getUrl('create'))->assertOk();
        $this->get(ProjectResource::getUrl('edit', ['record' => $project]))->assertOk();
        $this->get(ProjectResource::getUrl('board', ['record' => $project]))->assertOk()->assertSee('Chưa có công việc nào.');

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertActionVisible('start')
            ->assertActionHidden('resume')
            ->assertActionHidden('complete')
            ->callAction('start');

        $this->assertSame(ProjectStatus::Active, $project->refresh()->status);
    }
}
