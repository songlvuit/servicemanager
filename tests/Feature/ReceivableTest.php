<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Invoice;
use App\Services\Billing\ReceivableAging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsInvoices;
use Tests\TestCase;

class ReceivableTest extends TestCase
{
    use BuildsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_aging_buckets_have_the_right_boundaries(): void
    {
        $aging = app(ReceivableAging::class);

        $expected = [
            0 => 'current',
            1 => '1_30',
            30 => '1_30',
            31 => '31_60',
            60 => '31_60',
            61 => '61_90',
            90 => '61_90',
            91 => 'over_90',
            400 => 'over_90',
        ];

        foreach ($expected as $days => $bucket) {
            $this->assertSame($bucket, $aging->bucketForDays($days), "Quá hạn {$days} ngày");
        }
    }

    public function test_summary_groups_outstanding_amounts_by_age(): void
    {
        // Mỗi hóa đơn 11.000.000; quá hạn lần lượt 0, 1, 30, 31, 61 và 120 ngày.
        $invoices = [];

        foreach ([0, 1, 30, 31, 61, 120] as $daysOverdue) {
            $invoices[$daysOverdue] = $this->issuedInvoice([
                'invoice_date' => today()->subDays($daysOverdue + 30),
                'due_date' => today()->subDays($daysOverdue),
            ]);
        }

        // Thu một phần hóa đơn quá hạn 31 ngày, thu đủ hóa đơn quá hạn 61 ngày.
        $this->pay($invoices[31], 1000000);
        $this->pay($invoices[61], 11000000);

        // Hóa đơn nháp và đã huỷ không phải công nợ.
        $this->makeInvoice();

        $summary = app(ReceivableAging::class)->summary();

        $this->assertSame('54000000.00', $summary['total']);
        $this->assertSame(5, $summary['count']);
        $this->assertSame('11000000.00', $summary['buckets']['current']['amount']);
        $this->assertSame('22000000.00', $summary['buckets']['1_30']['amount']);
        $this->assertSame(2, $summary['buckets']['1_30']['count']);
        $this->assertSame('10000000.00', $summary['buckets']['31_60']['amount']);
        $this->assertSame('0.00', $summary['buckets']['61_90']['amount']);
        $this->assertSame('11000000.00', $summary['buckets']['over_90']['amount']);

        // Bộ lọc theo tuổi nợ trả về đúng các hóa đơn của nhóm.
        $inBucket = fn (string $bucket): array => app(ReceivableAging::class)
            ->scopeBucket(Invoice::query()->open(), $bucket)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$invoices[0]->getKey()], $inBucket('current'));
        $this->assertSame([$invoices[1]->getKey(), $invoices[30]->getKey()], $inBucket('1_30'));
        $this->assertSame([$invoices[31]->getKey()], $inBucket('31_60'));
        $this->assertSame([], $inBucket('61_90'));
        $this->assertSame([$invoices[120]->getKey()], $inBucket('over_90'));
    }

    public function test_receivable_screen_lists_open_invoices_with_days_overdue(): void
    {
        $overdue = $this->issuedInvoice(['invoice_date' => today()->subDays(50), 'due_date' => today()->subDays(12)]);
        $current = $this->issuedInvoice();
        $paid = $this->issuedInvoice();
        $this->pay($paid, 11000000);
        $draft = $this->makeInvoice();

        $this->actingAs($this->accountant());

        $this->get(ReceivableResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Tổng phải thu')
            ->assertSee('22.000.000')
            ->assertSee('12 ngày')
            ->assertSee('Quá hạn 1–30 ngày');

        Livewire::test(ListReceivables::class)
            ->assertCanSeeTableRecords([$overdue, $current])
            ->assertCanNotSeeTableRecords([$paid, $draft])
            ->filterTable('aging', '1_30')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$current]);
    }

    public function test_receivable_screen_uses_its_own_permission(): void
    {
        // Sales có quyền xem công nợ.
        $this->actingAs($this->createUserWithRole(SystemRole::Sales))
            ->get(ReceivableResource::getUrl('index'))
            ->assertOk();

        // Quản lý dự án không có quyền xem công nợ; màn hình chỉ xem với mọi người.
        $this->actingAs($this->createUserWithRole(SystemRole::ProjectManager))
            ->get(ReceivableResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        $this->assertFalse(ReceivableResource::canCreate());
        $this->assertFalse(ReceivableResource::canDelete($this->makeInvoice()));
    }
}
