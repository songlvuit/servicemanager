<?php

namespace Tests\Feature;

use App\Enums\BudgetCategory;
use App\Enums\CostStatus;
use App\Enums\SystemRole;
use App\Filament\Resources\BudgetItems\BudgetItemResource;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\BudgetItemsRelationManager;
use App\Models\BudgetItem;
use App\Models\User;
use App\Services\Projects\ProjectFinancials;
use App\Services\Projects\ProjectWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

class BudgetTest extends TestCase
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

    public function test_budget_items_roll_up_into_the_project(): void
    {
        $project = $this->makeProject(['contract_value' => 100000000]);

        $personnel = $project->budgetItems()->create(['category' => BudgetCategory::Personnel, 'planned_amount' => 40000000]);
        $project->budgetItems()->create(['category' => BudgetCategory::Equipment, 'planned_amount' => 25000000.50]);

        $project->refresh();

        $this->assertSame('65000000.50', $project->budget);
        // Lợi nhuận dự kiến = doanh thu − ngân sách.
        $this->assertSame('34999999.50', $project->expected_profit);
        // Chưa phát sinh chi phí: lợi nhuận thực tế = doanh thu, còn lại = ngân sách.
        $this->assertSame('100000000.00', $project->actual_profit);
        $this->assertSame('65000000.50', $project->remainingBudget());
        $this->assertSame('40000000.00', $personnel->refresh()->variance);

        $personnel->update(['planned_amount' => 30000000]);
        $this->assertSame('55000000.50', $project->refresh()->budget);

        $personnel->delete();
        $this->assertSame('25000000.50', $project->refresh()->budget);
    }

    public function test_cost_breakdown_lists_each_budgeted_category(): void
    {
        $project = $this->makeProject();
        $project->budgetItems()->create(['category' => BudgetCategory::Travel, 'planned_amount' => 5000000]);
        $project->budgetItems()->create(['category' => BudgetCategory::Personnel, 'planned_amount' => 20000000]);

        $rows = app(ProjectFinancials::class)->costBreakdown($project);

        // Theo thứ tự nhóm chi phí của hệ thống: Nhân sự trước Đi lại.
        $this->assertSame([BudgetCategory::Personnel, BudgetCategory::Travel], array_column($rows, 'category'));
        $this->assertSame('20000000.00', $rows[0]['planned']);
        $this->assertSame('20000000.00', $rows[0]['variance']);
        $this->assertSame(CostStatus::Ok, $rows[0]['status']);
    }

    public function test_cost_status_follows_the_budget_rules(): void
    {
        // Rule 6: thực tế ≥ 80% ngân sách → cảnh báo. Rule 7: thực tế > ngân sách → vượt.
        $this->assertSame(CostStatus::Ok, CostStatus::for('100000000', '79999999.99'));
        $this->assertSame(CostStatus::Warning, CostStatus::for('100000000', '80000000'));
        $this->assertSame(CostStatus::Warning, CostStatus::for('100000000', '100000000'));
        $this->assertSame(CostStatus::Over, CostStatus::for('100000000', '100000000.01'));
        $this->assertSame(CostStatus::NoBudget, CostStatus::for('0', '0'));
        // Phát sinh chi phí khi chưa có ngân sách cũng là vượt ngân sách.
        $this->assertSame(CostStatus::Over, CostStatus::for('0', '1'));

        config(['project.budget_warning_threshold' => 90]);

        $this->assertSame(CostStatus::Ok, CostStatus::for('100000000', '85000000'));
        $this->assertSame(CostStatus::Warning, CostStatus::for('100000000', '90000000'));
    }

    public function test_pm_adds_budget_from_the_project_page_once_per_category(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->pm);

        $component = Livewire::test(BudgetItemsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => ViewProject::class,
        ]);

        $component
            ->callAction(TestAction::make('create')->table(), [
                'category' => BudgetCategory::Production->value,
                'planned_amount' => 15000000,
                'description' => 'Bối cảnh, đạo cụ',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('15000000.00', $project->refresh()->budget);

        // Mỗi nhóm chi phí chỉ có một dòng ngân sách trong một dự án.
        $component
            ->callAction(TestAction::make('create')->table(), [
                'category' => BudgetCategory::Production->value,
                'planned_amount' => 1000000,
            ])
            ->assertHasFormErrors(['category']);

        $this->assertSame(1, $project->budgetItems()->count());
    }

    public function test_budget_of_a_closed_project_is_locked(): void
    {
        $project = $this->makeProject();
        $item = $project->budgetItems()->create(['category' => BudgetCategory::Other, 'planned_amount' => 1000000]);

        $this->assertTrue($this->pm->can('update', $item));

        $workflow = app(ProjectWorkflow::class);
        $workflow->complete($workflow->start($project, $this->pm), $this->pm);

        $item = BudgetItem::query()->find($item->getKey());

        $this->assertFalse($this->pm->can('update', $item));
        $this->assertFalse($this->pm->can('delete', $item));
    }

    public function test_budget_screen_respects_permissions(): void
    {
        $project = $this->makeProject(['name' => 'Dự án có ngân sách']);
        $project->budgetItems()->create(['category' => BudgetCategory::Software, 'planned_amount' => 7500000]);

        $this->actingAs($this->pm)
            ->get(BudgetItemResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Dự án có ngân sách')
            ->assertSee('7.500.000');

        // Sales không có quyền xem ngân sách.
        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(BudgetItemResource::getUrl('index'))
            ->assertForbidden();
    }
}
