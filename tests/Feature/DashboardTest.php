<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\SystemRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\ActionItems;
use App\Filament\Widgets\AttentionProjects;
use App\Filament\Widgets\ExpenseByMonth;
use App\Filament\Widgets\ExpenseVsBudget;
use App\Filament\Widgets\KpiOverview;
use App\Filament\Widgets\PendingActions;
use App\Filament\Widgets\ProfitByMonth;
use App\Filament\Widgets\ProjectProfitabilityChart;
use App\Filament\Widgets\ProjectStatusChart;
use App\Filament\Widgets\ReceivableAgingChart;
use App\Filament\Widgets\RecentActivity;
use App\Filament\Widgets\RevenueByMonth;
use App\Filament\Widgets\RevenueByServiceChart;
use App\Models\Customer;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsProjects;
use Tests\TestCase;

/**
 * Dashboard (mục 35–37, 41, 75): thẻ KPI, biểu đồ, cảnh báo, hoạt động gần đây.
 */
class DashboardTest extends TestCase
{
    use BuildsExpenses;
    use BuildsInvoices;
    use BuildsProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    }

    public function test_every_role_can_open_the_dashboard(): void
    {
        $this->seedBusyCompany();

        foreach (SystemRole::cases() as $role) {
            $this->actingAs($this->approver($role))
                ->get(Dashboard::getUrl())
                ->assertOk()
                ->assertSee('Cần xử lý')
                ->assertSee('Hoạt động gần đây');
        }
    }

    public function test_each_role_sees_only_the_widgets_it_is_allowed_to(): void
    {
        $finance = [RevenueByMonth::class, ExpenseByMonth::class, ProfitByMonth::class, RevenueByServiceChart::class, ReceivableAgingChart::class];
        $projects = [ExpenseVsBudget::class, ProjectProfitabilityChart::class, ProjectStatusChart::class, AttentionProjects::class];

        $expected = [
            SystemRole::Director->value => [...$finance, ...$projects],
            SystemRole::Accountant->value => [...$finance, ...$projects],
            // Kinh doanh: thấy doanh thu và công nợ, không thấy chi phí / lợi nhuận.
            SystemRole::Sales->value => [RevenueByMonth::class, RevenueByServiceChart::class, ReceivableAgingChart::class, ProjectStatusChart::class, AttentionProjects::class],
            SystemRole::ProjectManager->value => $projects,
            SystemRole::Employee->value => [ProjectStatusChart::class, AttentionProjects::class],
        ];

        foreach ($expected as $role => $visible) {
            $this->actingAs($this->approver(SystemRole::from($role)));

            foreach ([...$finance, ...$projects] as $widget) {
                $this->assertSame(
                    in_array($widget, $visible, true),
                    $widget::canView(),
                    class_basename($widget)." với vai trò {$role}",
                );
            }
        }
    }

    public function test_kpi_cards_show_this_months_figures_to_the_director(): void
    {
        $this->seedBusyCompany();

        Livewire::actingAs($this->approver(SystemRole::Director))
            ->test(KpiOverview::class)
            ->assertSeeInOrder(['Doanh thu tháng này', '40.000.000 ₫', '+100% so với tháng trước'])
            ->assertSeeInOrder(['Chi phí tháng này', '12.000.000 ₫'])
            ->assertSeeInOrder(['Lợi nhuận tháng này', '28.000.000 ₫'])
            ->assertSeeInOrder(['Biên lợi nhuận', '70%'])
            // Còn phải thu: 44.000.000 (tháng này) + 22.000.000 (tháng trước, quá hạn).
            ->assertSeeInOrder(['Công nợ phải thu', '66.000.000 ₫', '1 hóa đơn quá hạn · 22.000.000 ₫'])
            ->assertSeeInOrder(['Dự án đang chạy', '1 dự án vượt ngân sách']);
    }

    public function test_people_without_finance_access_get_personal_cards_instead(): void
    {
        $this->seedBusyCompany();

        $employee = $this->approver(SystemRole::Employee);
        $project = $this->makeProject(['status' => ProjectStatus::Active]);

        Task::factory()->create(['project_id' => $project->getKey(), 'assignee_id' => $employee->getKey(), 'due_date' => today()->subDay()]);
        Task::factory()->create(['project_id' => $project->getKey(), 'assignee_id' => $employee->getKey(), 'due_date' => today()->addWeek()]);

        Livewire::actingAs($employee)
            ->test(KpiOverview::class)
            ->assertDontSee('Doanh thu tháng này')
            ->assertDontSee('Chi phí tháng này')
            ->assertDontSee('Công nợ phải thu')
            ->assertSeeInOrder(['Dự án của tôi đang chạy', 'Công việc của tôi', '1 việc đã quá hạn']);
    }

    public function test_action_items_count_exactly_what_the_linked_lists_show(): void
    {
        $this->seedBusyCompany();

        $director = $this->approver(SystemRole::Director);
        $this->actingAs($director);

        $items = collect(ActionItems::for($director))->keyBy('key');

        $this->assertSame(1, $items['invoices_overdue']['count']);
        $this->assertSame(InvoiceResource::getUrl('index', ['tab' => 'overdue']), $items['invoices_overdue']['url']);
        $this->assertSame(ActionItems::DANGER, $items['invoices_overdue']['level']);

        $this->assertSame(1, $items['projects_over_budget']['count']);
        $this->assertSame(ProjectResource::getUrl('index', ['tab' => 'over_budget']), $items['projects_over_budget']['url']);

        // Đề nghị chi 30.000.000 đang chờ Giám đốc duyệt.
        $this->assertSame(1, $items['expenses_awaiting_me']['count']);
        $this->assertSame(ExpenseResource::getUrl('index', ['tab' => 'awaiting_me']), $items['expenses_awaiting_me']['url']);

        $this->assertSame(1, $items['payments_received']['count']);
        $this->assertSame(ActionItems::SUCCESS, $items['payments_received']['level']);

        // Con số bằng 0 thì không hiện.
        $this->assertFalse($items->has('contracts_expiring'));
        $this->assertFalse($items->has('quotations_pending'));

        // Kế toán không duyệt cấp Giám đốc → không có dòng "chờ bạn duyệt".
        $accountant = $this->approver(SystemRole::Accountant);
        $this->actingAs($accountant);

        $forAccountant = collect(ActionItems::for($accountant))->keyBy('key');

        $this->assertFalse($forAccountant->has('expenses_awaiting_me'));
        $this->assertSame(1, $forAccountant['invoices_overdue']['count']);

        Livewire::actingAs($director)
            ->test(PendingActions::class)
            ->assertSeeInOrder(['hóa đơn quá hạn thanh toán', 'dự án vượt ngân sách', 'đề nghị chi đang chờ bạn duyệt']);

        // Nhân viên không có việc gì cần xử lý → trạng thái trống.
        Livewire::actingAs($this->createUserWithRole(SystemRole::Employee))
            ->test(PendingActions::class)
            ->assertSee('Không có việc nào cần xử lý');
    }

    public function test_attention_table_lists_over_budget_low_margin_and_late_projects(): void
    {
        $healthy = $this->makeProject(['status' => ProjectStatus::Active, 'end_date' => today()->addMonth()]);
        $healthy->budgetItems()->create(['category' => 'other', 'description' => 'Sản xuất', 'planned_amount' => 50000000]);
        $this->payExpense($this->makeExpense($healthy, attributes: ['amount' => 5000000]));

        // Vượt ngân sách: ngân sách 1.000.000 mà đã chi 2.000.000.
        $overBudget = $this->makeProject(['status' => ProjectStatus::Active, 'end_date' => today()->addMonth()]);
        $overBudget->budgetItems()->create(['category' => 'other', 'description' => 'Dự phòng', 'planned_amount' => 1000000]);
        $this->payExpense($this->makeExpense($overBudget, attributes: ['amount' => 2000000]));

        // Biên lợi nhuận thấp: doanh thu 100 triệu, đã chi 85 triệu → 15%.
        $lowMargin = $this->makeProject(['status' => ProjectStatus::Active, 'end_date' => today()->addMonth()]);
        $lowMargin->budgetItems()->create(['category' => 'other', 'description' => 'Sản xuất', 'planned_amount' => 200000000]);
        $this->payExpense($this->makeExpense($lowMargin, attributes: ['amount' => 85000000]));

        $late = $this->makeProject(['status' => ProjectStatus::Active, 'end_date' => today()->subDays(3)]);

        // Dự án đã hoàn thành không còn cần chú ý dù trễ hạn.
        $done = $this->makeProject(['status' => ProjectStatus::Completed, 'end_date' => today()->subDays(3)]);

        $this->assertSame(['Vượt ngân sách'], AttentionProjects::reasons($overBudget->refresh(), true));
        $this->assertSame(['Biên lợi nhuận thấp'], AttentionProjects::reasons($lowMargin->refresh(), true));
        $this->assertSame(['Trễ hạn'], AttentionProjects::reasons($late->refresh(), true));

        Livewire::actingAs($this->approver(SystemRole::Director))
            ->test(AttentionProjects::class)
            ->assertCanSeeTableRecords([$overBudget, $lowMargin, $late])
            ->assertCanNotSeeTableRecords([$healthy, $done])
            ->assertTableColumnVisible('actual_cost');

        // Người không xem chi phí nội bộ chỉ thấy dự án trễ hạn, không có cột tiền.
        Livewire::actingAs($this->approver(SystemRole::Sales))
            ->test(AttentionProjects::class)
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$overBudget, $lowMargin, $healthy])
            ->assertTableColumnHidden('actual_cost')
            ->assertTableColumnHidden('profit_margin');
    }

    public function test_recent_activity_is_limited_to_own_actions_without_audit_permission(): void
    {
        $sales = $this->approver(SystemRole::Sales);

        $this->actingAs($sales);
        Customer::factory()->create(['name' => 'Khách của Kinh doanh']);

        $this->actingAs($this->approver(SystemRole::Accountant));
        Customer::factory()->create(['name' => 'Khách của Kế toán']);

        $mine = Customer::query()->where('name', 'Khách của Kinh doanh')->sole();
        $theirs = Customer::query()->where('name', 'Khách của Kế toán')->sole();

        Livewire::actingAs($sales)
            ->test(RecentActivity::class)
            ->assertSee('Khách hàng '.$mine->code)
            ->assertDontSee('Khách hàng '.$theirs->code)
            ->assertDontSee('Xem toàn bộ nhật ký');

        Livewire::actingAs($this->approver(SystemRole::Director))
            ->test(RecentActivity::class)
            ->assertSee('Khách hàng '.$mine->code)
            ->assertSee('Khách hàng '.$theirs->code)
            ->assertSee('Xem toàn bộ nhật ký');
    }

    public function test_charts_render_with_and_without_data(): void
    {
        $director = $this->approver(SystemRole::Director);

        $charts = [
            RevenueByMonth::class, ExpenseByMonth::class, ProfitByMonth::class, RevenueByServiceChart::class,
            ExpenseVsBudget::class, ProjectProfitabilityChart::class, ReceivableAgingChart::class, ProjectStatusChart::class,
        ];

        // Chưa có dữ liệu: mỗi biểu đồ hiện trạng thái trống, không lỗi.
        foreach ($charts as $chart) {
            Livewire::actingAs($director)->test($chart)->assertSuccessful();
        }

        $this->seedBusyCompany();

        foreach ($charts as $chart) {
            Livewire::actingAs($director)->test($chart)->assertSuccessful();
        }

        Livewire::actingAs($director)
            ->test(RevenueByMonth::class)
            ->set('filter', '6')
            ->assertSuccessful();
    }

    /**
     * Tháng này: doanh thu 40.000.000, chi phí 12.000.000. Tháng trước: doanh
     * thu 20.000.000 (hóa đơn đã quá hạn). Một dự án vượt ngân sách, một đề
     * nghị chi đang chờ Giám đốc duyệt, một thanh toán vừa nhận.
     */
    private function seedBusyCompany(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Active]);
        $project->budgetItems()->create(['category' => 'other', 'description' => 'Sản xuất', 'planned_amount' => 10000000]);

        $this->payExpense($this->makeExpense($project, attributes: ['amount' => 12000000, 'expense_date' => '2026-10-05']));

        // Chờ Giám đốc duyệt (trên 20 triệu phải qua cấp Giám đốc).
        $pending = $this->makeExpense($project, attributes: ['amount' => 30000000]);
        $workflow = $this->expenseWorkflow();
        $pending = $workflow->submit($pending, $pending->employee);

        while ($pending->status->isInApproval() && $pending->currentApproval()->first()->current_role !== SystemRole::Director->value) {
            $pending = $workflow->approve($pending, $this->approver(SystemRole::from($pending->currentApproval()->first()->current_role)));
        }

        $this->issuedInvoice(['invoice_date' => '2026-10-02', 'subtotal' => 40000000]);
        $this->issuedInvoice(['invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'subtotal' => 20000000]);

        $small = $this->issuedInvoice(['invoice_date' => '2026-08-10', 'subtotal' => 1000000]);
        $this->pay($small, 1100000);

        auth()->logout();
    }
}
