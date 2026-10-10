<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Timesheets\Pages\ManageTimesheets;
use App\Filament\Resources\Timesheets\Tables\TimesheetsTable;
use App\Filament\Resources\Timesheets\TimesheetResource;
use App\Filament\Support\TimesheetExport;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use BuildsJobs;
    use RefreshDatabase;

    private User $employee;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->employee = $this->createUserWithRole(SystemRole::Employee, ['session_rate' => 400000]);
        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager);
    }

    public function test_labor_cost_is_sessions_times_the_rate_at_the_time_of_logging(): void
    {
        $job = $this->makeJob();

        $entry = Timesheet::factory()->create([
            'task_id' => $job->getKey(),
            'user_id' => $this->employee->getKey(),
            // Một buổi rưỡi × 400.000 ₫ / buổi.
            'sessions' => 1.5,
        ]);

        $this->assertSame('400000.00', $entry->session_rate);
        $this->assertSame('600000.00', $entry->cost);
        $this->assertSame('600000.00', $job->refresh()->actual_cost);
        $this->assertSame('600000.00', $job->contract->refresh()->actual_cost);
        $this->assertFalse($entry->refresh()->is_auto);

        // Tăng đơn giá về sau không làm đổi chi phí đã ghi nhận.
        $this->employee->update(['session_rate' => 500000]);
        $entry->update(['description' => 'Bổ sung mô tả']);

        $this->assertSame('600000.00', $entry->refresh()->cost);

        // Sửa số buổi thì tính lại theo đơn giá đã chụp.
        $entry->update(['sessions' => 2]);

        $this->assertSame('800000.00', $entry->refresh()->cost);
        $this->assertSame('800000.00', $job->refresh()->actual_cost);

        $entry->delete();

        $this->assertSame('0.00', $job->refresh()->actual_cost);
        $this->assertSame('0.00', $job->contract->refresh()->actual_cost);

        // Việc trả theo giá riêng: nhập đơn giá cho chính dòng công đó.
        $special = Timesheet::factory()->create(['task_id' => $job->getKey(), 'user_id' => $this->employee->getKey(), 'sessions' => 2, 'session_rate' => 700000]);

        $this->assertSame('1400000.00', $special->cost);

        // Đổi sang người khác mà không nhập đơn giá: lấy đơn giá của người mới.
        $special->update(['user_id' => $this->createUserWithRole(SystemRole::Employee, ['session_rate' => 300000])->getKey()]);

        $this->assertSame('600000.00', $special->refresh()->cost);
    }

    public function test_task_actual_sessions_follow_its_timesheets(): void
    {
        $task = Task::factory()->create();
        $otherTask = Task::factory()->create();

        $first = Timesheet::factory()->create(['task_id' => $task->getKey(), 'sessions' => 3]);
        Timesheet::factory()->create(['task_id' => $task->getKey(), 'sessions' => 4.5]);

        $this->assertSame('7.50', $task->refresh()->actual_sessions);

        // Chuyển một dòng sang công việc khác: cả hai công việc được cộng lại.
        $first->update(['task_id' => $otherTask->getKey()]);

        $this->assertSame('4.50', $task->refresh()->actual_sessions);
        $this->assertSame('3.00', $otherTask->refresh()->actual_sessions);
    }

    public function test_employee_logs_and_sees_only_own_time(): void
    {
        $task = Task::factory()->create(['assignee_id' => $this->employee->getKey()]);
        $others = Timesheet::factory()->create(['task_id' => $task->getKey(), 'user_id' => $this->pm->getKey()]);

        $this->actingAs($this->employee);

        Livewire::test(ManageTimesheets::class)
            ->assertCanNotSeeTableRecords([$others])
            ->callAction('create', data: [
                'task_id' => $task->getKey(),
                'work_date' => today()->toDateString(),
                'sessions' => 1.5,
                'description' => 'Dựng phim',
            ])
            ->assertHasNoFormErrors();

        $mine = Timesheet::query()->where('user_id', $this->employee->getKey())->first();

        $this->assertNotNull($mine);
        $this->assertSame('600000.00', $mine->cost);
        $this->assertTrue($this->employee->can('update', $mine));
        $this->assertFalse($this->employee->can('view', $others));

        // Chi phí nhân sự là thông tin nội bộ: nhân viên không thấy cột này.
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertSee('Số buổi')->assertDontSee('Chi phí nhân sự')->assertDontSee('Đơn giá');

        // Quản lý công việc thấy công của mọi người, cả đơn giá và chi phí.
        $this->actingAs($this->pm);

        $this->assertSame(2, Timesheet::query()->visibleTo($this->pm)->count());
        $this->get(TimesheetResource::getUrl('index'))->assertOk()->assertSee('Chi phí nhân sự')->assertSee('Đơn giá / buổi');
    }

    public function test_sessions_must_be_within_a_day_and_not_in_the_future(): void
    {
        $this->actingAs($this->pm);

        Livewire::test(ManageTimesheets::class)
            ->callAction('create', data: [
                'user_id' => $this->employee->getKey(),
                'task_id' => Task::factory()->create()->getKey(),
                'work_date' => today()->addDay()->toDateString(),
                'sessions' => 25,
            ])
            ->assertHasFormErrors(['sessions', 'work_date']);
    }

    /**
     * Lọc theo nhân viên và theo tháng để xem tổng công của từng tháng.
     */
    public function test_timesheets_are_filtered_by_person_and_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));

        $other = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Sáng', 'session_rate' => 300000]);
        $task = Task::factory()->create(['name' => 'Quay TVC']);
        $row = fn (User $user, string $date, float $sessions, ?Task $onTask = null): Timesheet => Timesheet::factory()->create([
            'user_id' => $user->getKey(), 'task_id' => $onTask?->getKey(), 'work_date' => $date, 'sessions' => $sessions,
        ]);

        $october = $row($this->employee, '2026-10-03', 2, $task);
        $octoberOther = $row($other, '2026-10-05', 1, $task);
        $september = $row($this->employee, '2026-09-20', 1.5, $task);
        $overhead = $row($this->employee, '2026-10-06', 1);

        $this->assertSame(['2026-10' => 'Tháng 10/2026', '2026-09' => 'Tháng 09/2026'], TimesheetsTable::monthOptions());

        $this->actingAs($this->pm);

        // Mở ra ở tháng hiện tại; tổng của tháng: 4 buổi, 2 × 400.000 + 300.000 + 400.000.
        Livewire::test(ManageTimesheets::class)
            ->assertCanSeeTableRecords([$october, $octoberOther, $overhead])
            ->assertCanNotSeeTableRecords([$september])
            ->assertSee('1.500.000 ₫')
            // Một nhân viên trong tháng.
            ->filterTable('user_id', [$this->employee->getKey()])
            ->assertCanSeeTableRecords([$october, $overhead])
            ->assertCanNotSeeTableRecords([$octoberOther, $september])
            ->assertSee('1.200.000 ₫')
            // Cùng người đó, tháng trước.
            ->filterTable('month', '2026-09')
            ->assertCanSeeTableRecords([$september])
            ->assertCanNotSeeTableRecords([$october, $overhead])
            ->assertSee('600.000 ₫')
            // Bỏ lọc tháng: mọi tháng. Lọc theo công việc.
            ->resetTableFilters()
            ->removeTableFilter('month')
            ->filterTable('task_id', [$task->getKey()])
            ->assertCanSeeTableRecords([$october, $octoberOther, $september])
            ->assertCanNotSeeTableRecords([$overhead]);

        // Tab Chấm công của công việc không mặc định theo tháng: thấy công của mọi tháng.
        Livewire::test(TimesheetsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertCanSeeTableRecords([$october, $octoberOther, $september]);
    }

    public function test_labor_sheet_is_exported_to_excel_by_person_and_by_task(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));

        $other = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Bùi Sáng', 'session_rate' => 300000]);
        $this->employee->update(['name' => 'An Quay']);
        $customer = Customer::factory()->create(['name' => 'Công ty Hoa Sen']);
        $tvc = Task::factory()->create(['name' => 'Quay TVC', 'customer_id' => $customer->getKey()]);
        $live = Task::factory()->create(['name' => 'Livestream']);
        $row = fn (User $user, string $date, float $sessions, ?Task $task): Timesheet => Timesheet::factory()->create([
            'user_id' => $user->getKey(), 'task_id' => $task?->getKey(), 'work_date' => $date, 'sessions' => $sessions,
        ]);

        $row($this->employee, '2026-10-03', 2, $tvc);
        $row($this->employee, '2026-10-04', 1, $tvc);
        $row($this->employee, '2026-10-06', 1.5, $live);
        $row($other, '2026-10-03', 2, $tvc);
        $row($this->employee, '2026-10-07', 1, null);
        // Tháng trước: không nằm trong file khi đang lọc tháng 10.
        $row($this->employee, '2026-09-20', 3, $tvc);

        $this->actingAs($this->pm);

        $download = Livewire::test(ManageTimesheets::class)
            ->assertActionVisible('exportLabor')
            ->callAction('exportLabor')
            ->assertFileDownloaded()
            ->effects['download'];

        $this->assertStringStartsWith('bang-cong-', $download['name']);
        $this->assertStringEndsWith('.xlsx', $download['name']);

        $sheets = $this->readWorkbook(base64_decode($download['content']));

        $this->assertSame(['Theo nhân viên', 'Theo công việc', 'Chi tiết'], array_keys($sheets));

        // Trang 1 — theo từng nhân viên, từng công việc; có dòng cộng.
        $this->assertSame([
            ['BẢNG CÔNG THEO NHÂN VIÊN'],
            ['Kỳ: 03/10/2026 – 07/10/2026'],
            ['Nhân viên', 'Công việc', 'Khách hàng', 'Số ngày', 'Số buổi (công)', 'Đơn giá / buổi', 'Thành tiền'],
            ['An Quay', 'Quay TVC', 'Công ty Hoa Sen', 2, 3, 400000, 1200000],
            ['An Quay', 'Livestream', '', 1, 1.5, 400000, 600000],
            ['An Quay', 'Không gắn công việc (chi phí chung)', '', 1, 1, 400000, 400000],
            ['Cộng: An Quay', '', '', 4, 5.5, '', 2200000],
            ['Bùi Sáng', 'Quay TVC', 'Công ty Hoa Sen', 1, 2, 300000, 600000],
            ['Cộng: Bùi Sáng', '', '', 1, 2, '', 600000],
            ['TỔNG CỘNG', '', '', 5, 7.5, '', 2800000],
        ], $sheets['Theo nhân viên']);

        // Trang 2 — theo từng công việc, từng người.
        $this->assertContains(['Quay TVC', 'Công ty Hoa Sen', 'An Quay', 2, 3, 400000, 1200000], $sheets['Theo công việc']);
        $this->assertContains(['Quay TVC', 'Công ty Hoa Sen', 'Bùi Sáng', 1, 2, 300000, 600000], $sheets['Theo công việc']);
        $this->assertContains(['Cộng: Quay TVC', '', '', 3, 5, '', 1800000], $sheets['Theo công việc']);

        // Trang 3 — từng dòng công.
        $this->assertCount(6, $sheets['Chi tiết']);
        $this->assertSame(['03/10/2026', 'An Quay', 'Quay TVC', 'Công ty Hoa Sen', '', 2, 400000, 800000, 'Nhập tay'], $sheets['Chi tiết'][1]);

        // Xuất theo bộ lọc đang chọn: chỉ một nhân viên.
        $download = Livewire::test(ManageTimesheets::class)
            ->filterTable('user_id', [$other->getKey()])
            ->callAction('exportLabor')
            ->effects['download'];

        $this->assertSame(['TỔNG CỘNG', '', '', 1, 2, '', 600000], last($this->readWorkbook(base64_decode($download['content']))['Theo nhân viên']));

        // Mỗi lần xuất được ghi nhật ký.
        $this->assertSame(['XLSX, 5 dòng', 'XLSX, 1 dòng'], AuditLog::query()->where('action', 'exported')->orderBy('id')->pluck('description')->all());

        // Kế toán xuất được (tính lương); nhân viên thì không.
        $this->assertTrue($this->createUserWithRole(SystemRole::Accountant)->can('timesheets.export'));

        $this->actingAs($this->employee);

        Livewire::test(ManageTimesheets::class)->assertActionHidden('exportLabor');

        // Người xuất không được xem chi phí nội bộ thì file không có đơn giá, thành tiền.
        $withoutMoney = TimesheetExport::sheets(Timesheet::query()->with(['user', 'task.customer'])->get(), withMoney: false);

        $this->assertSame(['Nhân viên', 'Công việc', 'Khách hàng', 'Số ngày', 'Số buổi (công)'], $withoutMoney[0]['rows'][3][0]);
        $this->assertSame(['Ngày', 'Nhân viên', 'Công việc', 'Khách hàng', 'Nội dung', 'Số buổi (công)', 'Nguồn'], $withoutMoney[2]['rows'][0][0]);
    }

    /**
     * Đọc lại file .xlsx vừa xuất: tên trang tính => các dòng (bỏ dòng trống).
     *
     * @return array<string, list<list<mixed>>>
     */
    private function readWorkbook(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $content);

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();

                if (array_filter($values, fn (mixed $value): bool => $value !== '' && $value !== null) !== []) {
                    $rows[] = $values;
                }
            }

            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();
        unlink($path);

        return $sheets;
    }
}
