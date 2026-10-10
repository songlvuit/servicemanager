<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\CostLine;
use App\Models\PurchaseOrder;
use App\Models\Service;
use App\Models\Task;
use App\Models\Timesheet;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Reports\FinancialReport;
use App\Services\Reports\RevenueByService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsExpenses;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsQuotations;
use Tests\TestCase;

/**
 * Số liệu tài chính toàn công ty (mục 34–35, 62): doanh thu, chi phí, lợi
 * nhuận theo tháng và doanh thu theo dịch vụ.
 */
class FinancialReportTest extends TestCase
{
    use BuildsExpenses;
    use BuildsInvoices;
    use BuildsJobs;
    use BuildsQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    }

    public function test_revenue_is_the_pretax_value_of_issued_invoices_by_invoice_date(): void
    {
        // Tháng này: 10.000.000 + 5.000.000. Tháng trước: 20.000.000.
        $this->issuedInvoice(['invoice_date' => '2026-10-02']);
        $this->issuedInvoice(['invoice_date' => '2026-10-10', 'subtotal' => 5000000]);
        $this->issuedInvoice(['invoice_date' => '2026-09-30', 'subtotal' => 20000000]);

        // Không tính: hóa đơn nháp và hóa đơn đã huỷ.
        $this->makeInvoice(['invoice_date' => '2026-10-05', 'subtotal' => 99000000]);
        app(InvoiceWorkflow::class)->cancel(
            $this->issuedInvoice(['invoice_date' => '2026-10-06', 'subtotal' => 77000000]),
            $this->accountant(),
            'Xuất nhầm',
        );

        $report = app(FinancialReport::class);

        $this->assertSame('15000000.00', $report->month()['revenue']);
        $this->assertSame('20000000.00', $report->month(today()->subMonthNoOverflow())['revenue']);

        // Thu tiền không làm đổi doanh thu (doanh thu ghi nhận khi phát hành).
        $this->pay($this->issuedInvoice(['invoice_date' => '2026-10-11', 'subtotal' => 1000000]), 1100000);

        $this->assertSame('16000000.00', $report->month()['revenue']);
    }

    public function test_cost_counts_paid_expenses_received_orders_and_timesheets(): void
    {
        $job = $this->makeJob();

        // Đề nghị chi đã thanh toán: tính. Đã duyệt chưa thanh toán và nháp: không tính.
        $this->payExpense($this->makeExpense($job, attributes: ['amount' => 4000000, 'expense_date' => '2026-10-03']));
        $this->approveExpense($this->makeExpense($job, attributes: ['amount' => 9000000, 'expense_date' => '2026-10-04']));
        $this->makeExpense($job, attributes: ['amount' => 8000000, 'expense_date' => '2026-10-05']);

        // Đơn mua hàng đã nhận hàng: 2 × 3.000.000 trước thuế.
        $orders = app(PurchaseOrderWorkflow::class);
        $order = PurchaseOrder::factory()->create(['task_id' => $job->getKey()]);
        $order->items()->create(['description' => 'Micro', 'quantity' => 2, 'unit_price' => 3000000, 'tax_rate' => 10]);
        $orders->receive($orders->order($orders->recalculate($order), $this->approver(SystemRole::SuperAdmin)), $this->approver(SystemRole::SuperAdmin));

        // Đơn mới đặt, chưa nhận hàng: chưa là chi phí thực tế.
        $pending = PurchaseOrder::factory()->create(['task_id' => $job->getKey()]);
        $pending->items()->create(['description' => 'Đèn', 'quantity' => 1, 'unit_price' => 50000000, 'tax_rate' => 10]);
        $orders->order($orders->recalculate($pending), $this->approver(SystemRole::SuperAdmin));

        // Công: 2 buổi × 400.000.
        Timesheet::factory()->create([
            'task_id' => $job->getKey(),
            'user_id' => $this->createUserWithRole(SystemRole::Employee, ['session_rate' => 400000])->getKey(),
            'work_date' => '2026-10-07',
        ]);

        // Chi phí của tháng trước không lẫn vào tháng này.
        $this->payExpense($this->makeExpense($job, attributes: ['amount' => 2500000, 'expense_date' => '2026-09-20']));

        $report = app(FinancialReport::class);

        $this->assertSame('10800000.00', $report->month()['cost']);
        $this->assertSame('2500000.00', $report->month(today()->subMonthNoOverflow())['cost']);
    }

    public function test_cost_lines_always_add_up_to_the_tasks_actual_cost(): void
    {
        $first = $this->makeJob();
        $second = $this->makeJob();

        $this->payExpense($this->makeExpense($first, attributes: ['amount' => 4000000]));
        $this->payExpense($this->makeExpense($second, attributes: ['amount' => 1500000, 'tax' => 150000]));
        $this->approveExpense($this->makeExpense($second, attributes: ['amount' => 7000000]));

        $orders = app(PurchaseOrderWorkflow::class);
        $order = PurchaseOrder::factory()->create(['task_id' => $first->getKey()]);
        $order->items()->create(['description' => 'Thuê máy quay', 'quantity' => 3, 'unit_price' => 2000000, 'tax_rate' => 8]);
        $orders->receive($orders->order($orders->recalculate($order), $this->approver(SystemRole::SuperAdmin)), $this->approver(SystemRole::SuperAdmin));

        Timesheet::factory()->create([
            'task_id' => $second->getKey(),
            // 3 buổi × 300.000.
            'user_id' => $this->createUserWithRole(SystemRole::Employee, ['session_rate' => 300000])->getKey(),
            'sessions' => 3,
        ]);

        foreach ([$first, $second] as $job) {
            $this->assertSame(
                $job->refresh()->actual_cost,
                number_format((float) CostLine::query()->where('task_id', $job->getKey())->sum('amount'), 2, '.', ''),
                "Tổng dòng chi phí của {$job->name} phải bằng chi phí thực tế của công việc.",
            );
        }

        $this->assertSame('10000000.00', $first->actual_cost);
        $this->assertSame('2400000.00', $second->actual_cost);
        // Và bằng chi phí của hợp đồng mà công việc gắn vào.
        $this->assertSame('10000000.00', $first->contract->refresh()->actual_cost);
        $this->assertEquals(12400000, Task::query()->sum('actual_cost'));
    }

    public function test_profit_and_margin_per_month_and_series(): void
    {
        $job = $this->makeJob();

        $this->issuedInvoice(['invoice_date' => '2026-10-02', 'subtotal' => 40000000]);
        $this->payExpense($this->makeExpense($job, attributes: ['amount' => 10000000, 'expense_date' => '2026-10-03']));

        // Tháng 9 chỉ có chi phí → lỗ, biên lợi nhuận 0 vì không có doanh thu.
        $this->payExpense($this->makeExpense($job, attributes: ['amount' => 3000000, 'expense_date' => '2026-09-10']));

        $report = app(FinancialReport::class);

        $this->assertSame(
            ['revenue' => '40000000.00', 'cost' => '10000000.00', 'profit' => '30000000.00', 'margin' => '75.00'],
            $report->month(),
        );

        $series = $report->monthly(3);

        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_column($series, 'key'));
        $this->assertSame(['T8/26', 'T9/26', 'T10/26'], array_column($series, 'label'));
        $this->assertSame(['0.00', '-3000000.00', '30000000.00'], array_column($series, 'profit'));
        $this->assertSame(['0.00', '0.00', '75.00'], array_column($series, 'margin'));
    }

    public function test_invoice_revenue_is_split_across_the_quoted_services(): void
    {
        $filming = Service::factory()->create(['name' => 'Quay phim']);
        $editing = Service::factory()->create(['name' => 'Dựng phim']);

        // Báo giá: Quay phim 20.000.000, Dựng phim 10.000.000, một hạng mục tự nhập 10.000.000.
        $quotation = $this->makeQuotation([
            ['service_id' => $filming->getKey(), 'unit_price' => 20000000],
            ['service_id' => $editing->getKey(), 'unit_price' => 10000000],
            ['unit_price' => 10000000],
        ]);

        $contract = $this->makeActiveContract(['customer_id' => $quotation->customer_id, 'quotation_id' => $quotation->getKey()]);

        // Hóa đơn đợt 1 của hợp đồng: 10.000.000 → chia 50% / 25% / 25%.
        $invoice = $this->issuedInvoice(['contract_id' => $contract->getKey(), 'subtotal' => 10000000]);

        $this->assertSame(
            ['Quay phim' => '5000000.00', 'Dựng phim' => '2500000.00', RevenueByService::UNCLASSIFIED => '2500000.00'],
            app(RevenueByService::class)->allocate($invoice->load('contract.quotation.items.service')),
        );

        // Hóa đơn của hợp đồng không có báo giá → "Chưa phân loại".
        $this->issuedInvoice(['subtotal' => 4000000]);

        $this->assertSame(
            [RevenueByService::UNCLASSIFIED => '6500000.00', 'Quay phim' => '5000000.00', 'Dựng phim' => '2500000.00'],
            app(RevenueByService::class)->between(today()->startOfMonth(), today()->endOfMonth()),
        );
    }

    public function test_split_never_loses_a_dong_and_small_services_fold_into_other(): void
    {
        $items = [];

        foreach (['A', 'B', 'C'] as $name) {
            $items[] = ['service_id' => Service::factory()->create(['name' => "Dịch vụ {$name}"])->getKey(), 'unit_price' => 1000000];
        }

        $quotation = $this->makeQuotation($items);
        $contract = $this->makeActiveContract(['customer_id' => $quotation->customer_id, 'quotation_id' => $quotation->getKey()]);
        $invoice = $this->issuedInvoice(['contract_id' => $contract->getKey(), 'subtotal' => 10000000]);

        // 10.000.000 chia 3: phần lẻ dồn vào dịch vụ cuối, tổng vẫn đúng 10.000.000.
        $shares = app(RevenueByService::class)->allocate($invoice->load('contract.quotation.items.service'));

        $this->assertSame(['3333333.33', '3333333.33', '3333333.34'], array_values($shares));
        $this->assertSame(10000000.0, array_sum(array_map('floatval', $shares)));

        // Chỉ giữ 2 dịch vụ lớn nhất, phần còn lại gộp vào "Dịch vụ khác".
        $top = app(RevenueByService::class)->between(today()->startOfMonth(), today()->endOfMonth(), limit: 2);

        $this->assertCount(3, $top);
        $this->assertSame('3333333.33', $top[RevenueByService::OTHER]);
    }
}
