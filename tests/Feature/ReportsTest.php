<?php

namespace Tests\Feature;

use App\Enums\MarginBand;
use App\Enums\ProjectStatus;
use App\Enums\SystemRole;
use App\Filament\Pages\Reports\CostReport;
use App\Filament\Pages\Reports\ProfitReport;
use App\Filament\Pages\Reports\RevenueReport;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Support\TableExport;
use App\Models\AuditLog;
use App\Models\CostLine;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsProjects;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

/**
 * Báo cáo (mục 62) và xuất dữ liệu (mục 63).
 */
class ReportsTest extends TestCase
{
    use BuildsExpenses;
    use BuildsInvoices;
    use BuildsProjects;
    use BuildsQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    }

    public function test_reports_require_the_financial_report_permission(): void
    {
        foreach ([ProfitReport::class, RevenueReport::class, CostReport::class] as $page) {
            $this->actingAs($this->approver(SystemRole::Accountant))->get($page::getUrl())->assertOk();
            $this->actingAs($this->approver(SystemRole::Director))->get($page::getUrl())->assertOk();

            // Kinh doanh và quản lý dự án không xem báo cáo tài chính toàn công ty.
            $this->actingAs($this->approver(SystemRole::Sales))->get($page::getUrl())->assertForbidden();
            $this->actingAs($this->approver(SystemRole::ProjectManager))->get($page::getUrl())->assertForbidden();
        }
    }

    public function test_profit_report_shows_margin_bands_and_filters_by_them(): void
    {
        // Doanh thu mỗi dự án 100.000.000; chi 60 / 75 / 85 / 95 triệu → biên 40 / 25 / 15 / 5%.
        $projects = [];

        foreach (['good' => 60, 'normal' => 75, 'warning' => 85, 'danger' => 95] as $band => $costInMillions) {
            $project = $this->makeProject(['status' => ProjectStatus::Active]);
            $this->payExpense($this->makeExpense($project, attributes: ['amount' => $costInMillions * 1000000]));

            $projects[$band] = $project->refresh();

            $this->assertSame(MarginBand::from($band), MarginBand::for($project->profit_margin));
        }

        $this->assertSame('40.00', $projects['good']->profit_margin);
        $this->assertSame('5000000.00', $projects['danger']->actual_profit);

        $this->actingAs($this->approver(SystemRole::Accountant));

        Livewire::test(ProfitReport::class)
            ->assertCanSeeTableRecords(array_values($projects))
            // Biên lợi nhuận chung = Σ lợi nhuận / Σ doanh thu = 85 / 400.
            ->assertSee('21,25%')
            ->filterTable('margin_band', MarginBand::Danger->value)
            ->assertCanSeeTableRecords([$projects['danger']])
            ->assertCanNotSeeTableRecords([$projects['good'], $projects['normal'], $projects['warning']])
            ->removeTableFilter('margin_band')
            ->filterTable('margin_band', MarginBand::Normal->value)
            ->assertCanSeeTableRecords([$projects['normal']])
            ->assertCanNotSeeTableRecords([$projects['good'], $projects['warning'], $projects['danger']]);
    }

    public function test_revenue_report_lists_billed_invoices_and_filters_by_service(): void
    {
        $service = Service::factory()->create(['name' => 'Quay phim']);
        $quotation = $this->makeQuotation([['service_id' => $service->getKey()]]);
        $contract = $this->makeActiveContract(['customer_id' => $quotation->customer_id, 'quotation_id' => $quotation->getKey()]);

        $withService = $this->issuedInvoice(['contract_id' => $contract->getKey(), 'invoice_date' => '2026-10-02']);
        $other = $this->issuedInvoice(['invoice_date' => '2026-09-20', 'subtotal' => 30000000]);
        $draft = $this->makeInvoice(['invoice_date' => '2026-10-03']);

        $this->actingAs($this->approver(SystemRole::Accountant));

        Livewire::test(RevenueReport::class)
            ->assertCanSeeTableRecords([$withService, $other])
            ->assertCanNotSeeTableRecords([$draft])
            // Dòng tổng doanh thu trước thuế: 10.000.000 + 30.000.000.
            ->assertSee('40.000.000 ₫')
            ->filterTable('service', $service->getKey())
            ->assertCanSeeTableRecords([$withService])
            ->assertCanNotSeeTableRecords([$other])
            ->removeTableFilter('service')
            ->filterTable('invoice_date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
            ->assertCanSeeTableRecords([$withService])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_cost_report_lists_actual_cost_lines_from_every_source(): void
    {
        $project = $this->makeProject();
        $otherProject = $this->makeProject();

        $paid = $this->payExpense($this->makeExpense($project, attributes: ['amount' => 4000000]));
        $unpaid = $this->approveExpense($this->makeExpense($project, attributes: ['amount' => 9000000]));
        $timesheet = Timesheet::factory()->create([
            'project_id' => $otherProject->getKey(),
            'user_id' => $this->createUserWithRole(SystemRole::Employee, ['hourly_rate' => 100000])->getKey(),
        ]);

        $expenseLine = CostLine::query()->findOrFail('expense-'.$paid->getKey());
        $timesheetLine = CostLine::query()->findOrFail('timesheet-'.$timesheet->getKey());

        $this->assertNull(CostLine::query()->find('expense-'.$unpaid->getKey()));
        $this->assertSame('Chấm công', $timesheetLine->sourceLabel());

        $this->actingAs($this->approver(SystemRole::Accountant));

        Livewire::test(CostReport::class)
            ->assertCanSeeTableRecords([$expenseLine, $timesheetLine])
            ->assertCountTableRecords(2)
            ->assertSee('4.800.000 ₫')
            ->filterTable('project_id', [$project->getKey()])
            ->assertCanSeeTableRecords([$expenseLine])
            ->assertCanNotSeeTableRecords([$timesheetLine])
            ->removeTableFilter('project_id')
            ->filterTable('source', 'timesheet')
            ->assertCanSeeTableRecords([$timesheetLine])
            ->assertCanNotSeeTableRecords([$expenseLine]);
    }

    public function test_export_values_are_formatted_for_spreadsheets(): void
    {
        $this->assertSame(1234.5, TableExport::value('1234.50'));
        $this->assertSame(12, TableExport::value(12));
        $this->assertSame('15/10/2026', TableExport::value(today()));
        $this->assertSame('15/10/2026 09:00', TableExport::value(now()));
        $this->assertSame('Đang thực hiện', TableExport::value(ProjectStatus::Active));
        $this->assertSame('Có', TableExport::value(true));
        $this->assertNull(TableExport::value(null));

        // Mã số thuế, số điện thoại giữ nguyên là chữ (không mất số 0 đầu).
        $this->assertSame('0312345678', TableExport::value('0312345678'));

        // Chặn công thức độc hại khi mở bằng Excel.
        $this->assertSame("'=HYPERLINK(\"http://x\")", TableExport::value('=HYPERLINK("http://x")'));
        $this->assertSame("'+84 901", TableExport::value('+84 901'));

        $customer = Customer::factory()->create(['name' => 'Công ty ABC', 'tax_code' => '0312345678']);

        $this->assertSame(
            [$customer->code, 'Công ty ABC', '0312345678', 'ABC'],
            TableExport::row($customer, [
                'Mã' => 'code',
                'Tên' => 'name',
                'MST' => 'tax_code',
                'Viết tắt' => fn (Customer $customer): string => str($customer->name)->afterLast(' ')->toString(),
            ]),
        );
    }

    public function test_lists_export_the_filtered_rows_to_csv_and_excel(): void
    {
        $this->actingAs($this->approver(SystemRole::Director));

        Customer::factory()->create(['name' => 'Công ty Hoa Sen', 'phone' => '0901000001']);
        Customer::factory()->create(['name' => 'Công ty Bình Minh', 'phone' => '0901000002']);

        $response = Livewire::test(ListCustomers::class)
            ->searchTable('Hoa Sen')
            ->callAction('exportCsv')
            ->assertFileDownloaded();

        $csv = base64_decode($response->effects['download']['content']);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Mã,"Tên khách hàng"', $csv);
        $this->assertStringContainsString('Công ty Hoa Sen', $csv);
        $this->assertStringContainsString('0901000001', $csv);
        // Chỉ xuất những dòng đang lọc.
        $this->assertStringNotContainsString('Bình Minh', $csv);
        $this->assertStringEndsWith('.csv', $response->effects['download']['name']);

        $excel = Livewire::test(ListCustomers::class)->callAction('exportXlsx')->assertFileDownloaded();

        $this->assertStringEndsWith('.xlsx', $excel->effects['download']['name']);
        // File .xlsx là một file zip (bắt đầu bằng "PK").
        $this->assertStringStartsWith('PK', base64_decode($excel->effects['download']['content']));

        // Mỗi lần xuất đều được ghi vào nhật ký.
        $logs = AuditLog::query()->where('action', 'exported')->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('Danh sách khách hàng', $logs[0]->label);
        $this->assertSame('CSV, 1 dòng', $logs[0]->description);
        $this->assertSame('XLSX, 2 dòng', $logs[1]->description);
    }

    public function test_export_needs_the_export_permission_and_hides_costs_from_sales(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Active]);
        $this->payExpense($this->makeExpense($project, attributes: ['amount' => 12345000]));
        $this->issuedInvoice();

        // Kinh doanh xem được dự án, hóa đơn nhưng không có quyền xuất hai danh sách này.
        $this->actingAs($this->approver(SystemRole::Sales));

        Livewire::test(ListProjects::class)->assertActionHidden('exportCsv');
        Livewire::test(ListInvoices::class)->assertActionHidden('exportCsv');
        Livewire::test(ListCustomers::class)->assertActionVisible('exportCsv');

        // Quản lý dự án xuất được dự án, có cột chi phí.
        $this->actingAs($this->approver(SystemRole::ProjectManager));

        $csv = base64_decode(Livewire::test(ListProjects::class)->callAction('exportCsv')->effects['download']['content']);

        $this->assertStringContainsString('"Chi phí thực tế"', $csv);
        $this->assertStringContainsString('12345000', $csv);

        // Báo cáo tài chính có nút xuất riêng theo quyền của báo cáo.
        $this->actingAs($this->approver(SystemRole::Accountant));

        $report = base64_decode(Livewire::test(ProfitReport::class)->callAction('exportCsv')->assertFileDownloaded()->effects['download']['content']);

        $this->assertStringContainsString($project->code, $report);
        $this->assertStringContainsString('87655000', $report);
    }
}
