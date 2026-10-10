<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Timesheets\Pages\ManageTimesheets;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Tasks\TaskLabor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Chấm công theo BUỔI (công) và công tự sinh theo phân công: người quản lý chỉ
 * cần phân công, chi phí nhân sự của công việc (và của hợp đồng) tự có — xem
 * App\Services\Tasks\TaskLabor.
 */
class TaskLaborTest extends TestCase
{
    use BuildsJobs;
    use RefreshDatabase;

    private User $pm;

    private User $quay;

    private User $sang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        // Thứ Tư 07/10/2026.
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager, ['name' => 'Dự']);
        // Đơn giá một buổi (công).
        $this->quay = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Quay', 'session_rate' => 600000]);
        $this->sang = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Sáng', 'session_rate' => 400000]);
    }

    public function test_assigning_people_to_a_task_creates_their_labor_cost(): void
    {
        $contract = $this->makeActiveContract(['contract_value' => 20000000]);

        $this->actingAs($this->pm);

        // Quản lý chỉ tạo công việc và phân công — không chấm công gì cả.
        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'name' => 'Livestream hội thảo',
                'assignee_id' => $this->quay->getKey(),
                'crew_ids' => [$this->sang->getKey()],
                'start_date' => '2026-10-09',
                'is_all_day' => false,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'budget' => 3000000,
            ])
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();
        $rows = $task->timesheets()->with('user')->orderBy('user_id')->get();

        // Một buổi sáng: mỗi người 1 buổi × đơn giá buổi của người đó.
        $this->assertSame(['Quay', 'Sáng'], $rows->pluck('user.name')->all());
        $this->assertSame(['1.00', '1.00'], $rows->pluck('sessions')->all());
        $this->assertSame(['600000.00', '400000.00'], $rows->pluck('cost')->all());
        $this->assertSame(['2026-10-09', '2026-10-09'], $rows->map(fn (Timesheet $row): string => $row->work_date->toDateString())->all());
        $this->assertTrue($rows->every(fn (Timesheet $row): bool => $row->is_auto));

        $task->refresh();
        $this->assertSame('1000000.00', $task->actual_cost);
        $this->assertSame('1000000.00', $task->committed_cost);
        $this->assertSame('2.00', $task->actual_sessions);
        $this->assertSame(['sessions' => '2.00', 'cost' => '1000000.00', 'people' => 2], app(TaskLabor::class)->summary($task));

        // Gắn hợp đồng: chi phí nhân sự vào luôn chi phí và lợi nhuận của hợp đồng.
        $task->linkToContract(['contract_id' => $contract->getKey()]);

        $this->assertSame('1000000.00', $contract->refresh()->actual_cost);
        $this->assertSame('19000000.00', $contract->actual_profit);

        // Trang công việc cho thấy ngay phần nhân sự.
        $this->get(TaskResource::getUrl('view', ['record' => $task]))
            ->assertOk()
            ->assertSee('Trong đó: nhân sự')
            ->assertSee('1.000.000 ₫')
            ->assertSee('2 buổi (công) của 2 người');
    }

    public function test_sessions_per_day_follow_the_length_of_the_work(): void
    {
        $sessions = function (array $attributes): array {
            $task = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-09', ...$attributes]);

            return $task->timesheets()->orderBy('work_date')->get()
                ->map(fn (Timesheet $row): string => $row->work_date->format('d/m').': '.$row->sessions)
                ->all();
        };
        $timed = fn (string $from, string $to, array $more = []): array => $sessions(['is_all_day' => false, 'start_time' => $from, 'end_time' => $to, ...$more]);

        // Một buổi khoảng 4 giờ; làm tròn, ít nhất 1 buổi.
        $this->assertSame(['09/10: 1.00'], $timed('08:00', '12:00'));
        $this->assertSame(['09/10: 1.00'], $timed('09:00', '10:00'));
        $this->assertSame(['09/10: 1.00'], $timed('13:00', '17:30'));
        $this->assertSame(['09/10: 2.00'], $timed('08:00', '17:00'));
        $this->assertSame(['09/10: 2.00'], $timed('13:00', '21:00'));
        $this->assertSame(['09/10: 3.00'], $timed('07:00', '19:00'));

        // Cả ngày: 2 buổi mỗi ngày; nhiều ngày thì mỗi ngày một dòng.
        $this->assertSame(['09/10: 2.00'], $sessions([]));
        $this->assertSame(['09/10: 2.00', '10/10: 2.00', '11/10: 2.00'], $sessions(['due_date' => '2026-10-11']));
        $this->assertSame(['09/10: 1.00', '10/10: 1.00'], $timed('08:00', '12:00', ['due_date' => '2026-10-10']));

        // "Việc dài ngày" (cả ngày, hơn 3 ngày), việc chưa có ngày bắt đầu, việc chưa giao ai: không tự tính công.
        $this->assertSame([], $sessions(['due_date' => '2026-10-20']));
        $this->assertSame([], $sessions(['start_date' => null]));
        $this->assertSame([], $sessions(['assignee_id' => null]));

        // Cách tính đổi được ở Hệ thống → Cấu hình.
        config(['job.session_hours' => 5, 'job.sessions_per_day' => 1]);

        $this->assertSame(['09/10: 1.00'], $timed('08:00', '14:00'));
        $this->assertSame(['09/10: 1.00'], $sessions([]));
    }

    public function test_labor_follows_every_change_of_the_assignment(): void
    {
        $task = Task::factory()->create([
            'name' => 'Quay TVC',
            'assignee_id' => $this->quay->getKey(),
            'crew_ids' => [$this->sang->getKey()],
            'start_date' => '2026-10-09',
            'is_all_day' => false,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'budget' => 5000000,
        ]);
        $cost = fn (): string => $task->refresh()->actual_cost;
        $lines = fn (): array => $task->timesheets()->with('user')->orderBy('user_id')->orderBy('work_date')->get()
            ->map(fn (Timesheet $row): string => $row->user->name.' '.$row->work_date->format('d/m').' '.$row->sessions)
            ->all();

        $this->assertSame('1000000.00', $cost());

        // Kéo dài cả ngày: 2 buổi mỗi người.
        $task->update(['end_time' => '17:00']);
        $this->assertSame(['Quay 09/10 2.00', 'Sáng 09/10 2.00'], $lines());
        $this->assertSame('2000000.00', $cost());

        // Rút Sáng khỏi ê-kíp, thêm quản lý (chưa có đơn giá: 0 đồng).
        $task->update(['crew_ids' => [$this->pm->getKey()]]);
        $this->assertSame(['Dự 09/10 2.00', 'Quay 09/10 2.00'], $lines());
        $this->assertSame('1200000.00', $cost());

        // Dời ngày, thêm một ngày.
        $task->update(['start_date' => '2026-10-12', 'due_date' => '2026-10-13', 'crew_ids' => []]);
        $this->assertSame(['Quay 12/10 2.00', 'Quay 13/10 2.00'], $lines());
        $this->assertSame('2400000.00', $cost());

        // Đổi nội dung khác (tên, địa điểm, ngân sách) không đụng tới công.
        $ids = $task->timesheets()->pluck('id')->all();
        $task->update(['name' => 'Quay TVC bản 2', 'location' => 'Studio A', 'budget' => 6000000]);
        $this->assertSame($ids, $task->timesheets()->pluck('id')->all());

        // Hoàn thành: công giữ nguyên. Huỷ: không còn công. Mở lại: có lại.
        $task->update(['status' => TaskStatus::Done]);
        $this->assertSame('2400000.00', $cost());

        $task->update(['status' => TaskStatus::Cancelled]);
        $this->assertSame([], $lines());
        $this->assertSame('0.00', $cost());

        $task->update(['status' => TaskStatus::InProgress]);
        $this->assertSame('2400000.00', $cost());

        // Bỏ ngày bắt đầu (không lên lịch nữa): không còn công. Xoá công việc: không để lại dòng công nào.
        $task->update(['start_date' => null]);
        $this->assertSame([], $lines());

        $task->update(['start_date' => '2026-10-12']);
        $this->assertSame(2, Timesheet::query()->count());

        $task->delete();
        $this->assertSame(0, Timesheet::query()->count());
    }

    public function test_labor_of_subtasks_counts_towards_the_parent_and_overbudget_is_notified_once(): void
    {
        $director = $this->createUserWithRole(SystemRole::Director);
        $parent = $this->makeJob(['name' => 'Sản xuất TVC', 'budget' => 1500000]);

        $child = Task::factory()->create(['parent_id' => $parent->getKey(), 'assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-09']);

        // Quay cả ngày: 2 buổi × 600.000 — tính vào việc con, việc cha và hợp đồng.
        $this->assertSame('1200000.00', $child->refresh()->actual_cost);
        $this->assertSame('1200000.00', $parent->refresh()->actual_cost);
        $this->assertSame('1200000.00', $parent->contract->refresh()->actual_cost);
        $this->assertSame(0, $director->notifications()->count());

        // Thêm Sáng: vượt ngân sách của việc cha → báo một lần.
        $child->update(['crew_ids' => [$this->sang->getKey()]]);

        $this->assertSame('2000000.00', $parent->refresh()->actual_cost);
        $this->assertTrue($parent->isOverBudget());
        $this->assertSame(1, $director->notifications()->where('data->title', 'Công việc vượt ngân sách')->count());
    }

    public function test_manual_corrections_are_never_overwritten(): void
    {
        $task = Task::factory()->create([
            'assignee_id' => $this->quay->getKey(),
            'crew_ids' => [$this->sang->getKey()],
            'start_date' => '2026-10-09',
            'due_date' => '2026-10-10',
        ]);
        $row = fn (User $user, string $date): Timesheet => $task->timesheets()->where('user_id', $user->getKey())->whereDate('work_date', $date)->sole();

        // 2 người × 2 ngày × 2 buổi.
        $this->assertSame(4, $task->timesheets()->count());
        $this->assertSame('4000000.00', $task->refresh()->actual_cost);

        $this->actingAs($this->pm);

        $tab = fn () => Livewire::test(TimesheetsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);

        // Quản lý chỉnh: Sáng ngày 10 chỉ làm nửa buổi với giá riêng; Quay ngày 10 không tính công.
        $tab()
            ->assertCountTableRecords(4)
            ->assertSee('Tự động')
            ->assertSee('Công của nhân sự')
            // Dòng tự sinh không có nút xoá (xoá sẽ bị sinh lại): sửa số buổi về 0.
            ->assertActionHidden(TestAction::make('delete')->table($row($this->sang, '2026-10-10')))
            ->callAction(TestAction::make('edit')->table($row($this->sang, '2026-10-10')), ['sessions' => 0.5, 'session_rate' => 1000000])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('edit')->table($row($this->quay, '2026-10-10')), ['sessions' => 0])
            ->assertHasNoFormErrors();

        $this->assertFalse($row($this->sang, '2026-10-10')->is_auto);
        $this->assertSame('500000.00', $row($this->sang, '2026-10-10')->cost);
        $this->assertSame('0.00', $row($this->quay, '2026-10-10')->cost);
        // 1.200.000 + 800.000 (ngày 9) + 500.000 + 0 (ngày 10).
        $this->assertSame('2500000.00', $task->refresh()->actual_cost);

        // Công việc đổi giờ: dòng tự sinh đổi theo, dòng đã chỉnh tay giữ nguyên và không bị sinh trùng.
        $task->update(['is_all_day' => false, 'start_time' => '08:00', 'end_time' => '12:00']);

        $this->assertSame(4, $task->timesheets()->count());
        $this->assertSame('1.00', $row($this->quay, '2026-10-09')->sessions);
        $this->assertSame('0.50', $row($this->sang, '2026-10-10')->sessions);
        $this->assertSame('0.00', $row($this->quay, '2026-10-10')->sessions);
        $this->assertSame('1500000.00', $task->refresh()->actual_cost);

        // Nút "Tính lại theo phân công" cũng không đụng tới dòng nhập tay; dòng tự sinh lỡ mất thì được sinh lại.
        Timesheet::query()->whereKey($row($this->quay, '2026-10-09')->getKey())->delete();

        $tab()
            ->callAction(TestAction::make('syncLabor')->table())
            ->assertNotified('Đã thêm 1, cập nhật 0, bỏ 0 dòng công.');

        $this->assertSame('1500000.00', $task->refresh()->actual_cost);

        $tab()
            ->callAction(TestAction::make('syncLabor')->table())
            ->assertNotified('Công đã khớp với phân công.');

        // Thêm công cho người ngoài ê-kíp (nhập tay) vẫn được, và xoá được.
        $tab()
            ->callAction(TestAction::make('create')->table(), ['user_id' => $this->pm->getKey(), 'work_date' => '2026-10-07', 'sessions' => 1, 'session_rate' => 300000])
            ->assertHasNoFormErrors();

        $extra = $row($this->pm, '2026-10-07');

        $this->assertFalse($extra->is_auto);
        $this->assertSame('1800000.00', $task->refresh()->actual_cost);
        $this->assertTrue($this->pm->can('delete', $extra));
        $this->assertFalse($this->pm->can('delete', $row($this->sang, '2026-10-09')));

        // Xoá công việc: dòng tự sinh mất, dòng nhập tay ở lại thành chi phí chung.
        $task->delete();

        $this->assertSame(0, Timesheet::query()->where('is_auto', true)->count());
        $this->assertSame(3, Timesheet::query()->whereNull('task_id')->count());
    }

    public function test_changing_a_persons_rate_applies_from_today_on(): void
    {
        $past = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-05']);
        $today = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-07']);
        $future = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-20']);
        $adjusted = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-21']);
        // Dòng đã chỉnh tay (giá riêng) không đổi theo đơn giá mới.
        $adjusted->timesheets()->sole()->update(['session_rate' => 900000]);

        $this->actingAs($this->createUserWithRole(SystemRole::SuperAdmin));

        Livewire::test(EditUser::class, ['record' => $this->quay->getRouteKey()])
            ->assertSee('Đơn giá / buổi (công)')
            ->fillForm(['session_rate' => 700000])
            ->call('save')
            ->assertHasNoFormErrors();

        // 2 buổi cả ngày: việc đã qua giữ 600.000, việc hôm nay và sắp tới theo 700.000.
        $this->assertSame('1200000.00', $past->refresh()->actual_cost);
        $this->assertSame('1400000.00', $today->refresh()->actual_cost);
        $this->assertSame('1400000.00', $future->refresh()->actual_cost);
        $this->assertSame('1800000.00', $adjusted->refresh()->actual_cost);
    }

    public function test_people_see_their_own_sessions_but_not_the_money_and_cannot_change_generated_ones(): void
    {
        $task = Task::factory()->create([
            'name' => 'Livestream ra mắt',
            'assignee_id' => $this->quay->getKey(),
            'crew_ids' => [$this->sang->getKey()],
            'start_date' => '2026-10-09',
        ]);
        $mine = $task->timesheets()->where('user_id', $this->sang->getKey())->sole();
        $theirs = $task->timesheets()->where('user_id', $this->quay->getKey())->sole();

        $this->actingAs($this->sang);

        // Bảng công của tôi: thấy số buổi, không thấy đơn giá và chi phí, không thấy công của người khác.
        Livewire::test(ManageTimesheets::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('Livestream ra mắt')
            ->assertSee('Số buổi')
            ->assertDontSee('Đơn giá')
            ->assertDontSee('Chi phí nhân sự')
            ->assertDontSee('400.000');

        // Công tự sinh do người quản lý chỉnh; nhân viên không tự sửa, không xoá.
        $this->assertFalse($this->sang->can('update', $mine));
        $this->assertFalse($this->sang->can('delete', $mine));
        $this->assertTrue($this->pm->can('update', $mine));

        // Nhân viên vẫn tự chấm thêm công của mình (không nhập được đơn giá).
        Livewire::test(ManageTimesheets::class)
            ->callAction('create', data: ['task_id' => $task->getKey(), 'work_date' => '2026-10-07', 'sessions' => 1, 'session_rate' => 5000000, 'description' => 'Chuẩn bị thiết bị'])
            ->assertHasNoFormErrors();

        $added = Timesheet::query()->where('description', 'Chuẩn bị thiết bị')->sole();

        $this->assertSame('400000.00', $added->session_rate);
        $this->assertTrue($this->sang->can('update', $added));

        // Trang công việc của nhân viên không có phần tiền.
        $this->get(TaskResource::getUrl('view', ['record' => $task]))
            ->assertOk()
            ->assertDontSee('Trong đó: nhân sự')
            ->assertDontSee('₫');
    }

    public function test_generating_labor_for_existing_tasks_skips_days_already_logged(): void
    {
        // Dữ liệu của bản trước: công việc đã phân công nhưng chưa có dòng công tự sinh.
        $task = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'crew_ids' => [$this->sang->getKey()], 'start_date' => '2026-10-05']);
        Timesheet::query()->delete();
        // Quay đã được chấm tay 1 buổi cho ngày đó.
        Timesheet::factory()->create(['task_id' => $task->getKey(), 'user_id' => $this->quay->getKey(), 'work_date' => '2026-10-05', 'sessions' => 1]);
        $cancelled = Task::factory()->create(['assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-05', 'status' => TaskStatus::Cancelled]);

        $labor = app(TaskLabor::class);

        // Chỉ sinh cho Sáng; chạy lại không sinh thêm.
        $this->assertSame(1, $labor->backfill());
        $this->assertSame(0, $labor->backfill());

        $this->assertSame(2, $task->timesheets()->count());
        // 1 × 600.000 (chấm tay) + 2 × 400.000 (tự sinh).
        $this->assertSame('1400000.00', $task->refresh()->actual_cost);
        $this->assertSame(0, $cancelled->timesheets()->count());
    }
}
