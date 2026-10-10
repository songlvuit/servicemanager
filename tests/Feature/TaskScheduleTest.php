<?php

namespace Tests\Feature;

use App\Enums\ScheduleStatus;
use App\Enums\SystemRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\WorkCalendar;
use App\Filament\Resources\Tasks\Pages\ManageTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\ScheduleRelationManager;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Task;
use App\Models\User;
use App\Services\Scheduling\CalendarQuery;
use App\Services\Scheduling\ScheduleConflicts;
use App\Services\Scheduling\ScheduleManager;
use App\Services\Scheduling\TaskSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Lịch làm việc lấy từ Công việc: công việc có ngày bắt đầu tự có lịch, lịch
 * đổi theo công việc, và trang Lịch không còn là nơi nhập công việc.
 */
class TaskScheduleTest extends TestCase
{
    use BuildsJobs;
    use BuildsSchedules;
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
        $this->quay = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Quay', 'session_rate' => 150000]);
        $this->sang = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Sáng', 'session_rate' => 100000]);
    }

    public function test_a_task_with_a_start_date_gets_its_schedule(): void
    {
        $customer = Customer::factory()->create();
        $type = ScheduleType::query()->where('name', 'Quay phim')->first();

        $task = $this->makeTask([
            'name' => 'Quay phóng sự doanh nghiệp',
            'description' => 'Mang theo flycam',
            'customer_id' => $customer->getKey(),
            'start_date' => '2026-10-08',
            'is_all_day' => false,
            'start_time' => '13:30',
            'end_time' => '17:00',
            'schedule_type_id' => $type->getKey(),
            'crew_ids' => [$this->sang->getKey()],
            'location' => 'Vĩnh Long',
            'equipment' => '2 cam fix, 1 gimbal',
            'external_crew' => '1 trợ lý',
        ]);

        $entry = ScheduleEntry::query()->with(['crew', 'type'])->sole();

        $this->assertTrue($entry->from_task);
        $this->assertTrue($entry->is($task->schedule));
        $this->assertSame('Quay phóng sự doanh nghiệp', $entry->title);
        $this->assertSame('Quay phim', $entry->type->name);
        $this->assertSame('2026-10-08 13:30:00', $entry->starts_at->toDateTimeString());
        $this->assertSame('2026-10-08 17:00:00', $entry->ends_at->toDateTimeString());
        $this->assertFalse($entry->is_all_day);
        $this->assertSame($customer->getKey(), $entry->customer_id);
        $this->assertSame('Vĩnh Long', $entry->location);
        $this->assertSame('2 cam fix, 1 gimbal', $entry->equipment);
        $this->assertSame('1 trợ lý', $entry->external_crew);
        $this->assertSame('Mang theo flycam', $entry->notes);
        $this->assertSame(ScheduleStatus::Confirmed, $entry->status);
        // Người phụ trách luôn có trong ê-kíp.
        $this->assertEqualsCanonicalizing([$this->quay->getKey(), $this->sang->getKey()], $entry->crew->pluck('id')->all());

        // Công việc chưa có ngày bắt đầu thì chưa lên lịch, kể cả khi có hạn.
        $this->makeTask(['name' => 'Viết kịch bản', 'start_date' => null, 'due_date' => '2026-10-12']);

        $this->assertSame(1, ScheduleEntry::query()->count());
    }

    public function test_dates_and_times_decide_the_span_on_the_calendar(): void
    {
        $span = fn (array $attributes): ?string => $this->makeTask($attributes)->schedule?->timeLabel();

        // Không nhập giờ: cả ngày. Có hạn sau ngày bắt đầu: nhiều ngày.
        $this->assertSame('Cả ngày', $span(['start_date' => '2026-10-08']));
        $this->assertSame('08/10 – 10/10 (cả ngày)', $span(['start_date' => '2026-10-08', 'due_date' => '2026-10-10']));
        $this->assertSame('08/10 08:00 – 09/10 18:00', $span(['start_date' => '2026-10-08', 'due_date' => '2026-10-09', 'is_all_day' => false, 'start_time' => '08:00', 'end_time' => '18:00']));

        // Hạn trước ngày bắt đầu, giờ sai: không làm hỏng việc lưu công việc, lịch lấy cách hiểu an toàn.
        $this->assertSame('Cả ngày', $span(['start_date' => '2026-10-08', 'due_date' => '2026-10-01']));
        $this->assertSame('Cả ngày', $span(['start_date' => '2026-10-08', 'is_all_day' => false, 'start_time' => '17:00', 'end_time' => '09:00']));
        $this->assertSame('Cả ngày', $span(['start_date' => '2026-10-08', 'is_all_day' => false, 'start_time' => null, 'end_time' => null]));

        // Công việc kéo dài quá 2 tháng không lên lịch.
        $this->assertNull($span(['start_date' => '2026-10-08', 'due_date' => '2027-03-01']));
    }

    public function test_the_schedule_follows_every_change_of_the_task(): void
    {
        $contract = $this->makeActiveContract();
        $task = $this->makeTask(['name' => 'Livestream', 'start_date' => '2026-10-08', 'is_all_day' => false, 'start_time' => '08:00', 'end_time' => '12:00']);
        $entryId = $task->schedule->getKey();

        $task->update([
            'name' => 'Livestream hội thảo',
            'contract_id' => $contract->getKey(),
            'assignee_id' => $this->sang->getKey(),
            'crew_ids' => [],
            'start_date' => '2026-10-09',
            'start_time' => '14:00',
            'end_time' => '18:00',
            'location' => 'Studio A',
            'is_tentative' => true,
        ]);

        $entry = ScheduleEntry::query()->with('crew')->sole();

        // Vẫn là lịch đó, chỉ đổi nội dung.
        $this->assertSame($entryId, $entry->getKey());
        $this->assertSame('Livestream hội thảo', $entry->title);
        $this->assertSame('2026-10-09 14:00:00', $entry->starts_at->toDateTimeString());
        // Gắn hợp đồng thì khách hàng của công việc (và của lịch) là khách hàng của hợp đồng.
        $this->assertSame($contract->customer_id, $entry->customer_id);
        $this->assertSame('Studio A', $entry->location);
        $this->assertSame(ScheduleStatus::Tentative, $entry->status);
        $this->assertSame([$this->sang->getKey()], $entry->crew->pluck('id')->all());

        // Trạng thái công việc quyết định trạng thái lịch.
        $task->update(['is_tentative' => false, 'status' => TaskStatus::InProgress]);
        $this->assertSame(ScheduleStatus::Confirmed, $entry->refresh()->status);

        $task->update(['status' => TaskStatus::Done]);
        $this->assertSame(ScheduleStatus::Done, $entry->refresh()->status);

        $task->update(['status' => TaskStatus::Cancelled]);
        $this->assertSame(ScheduleStatus::Cancelled, $entry->refresh()->status);

        // Đổi thứ không liên quan tới lịch thì lịch không bị ghi lại.
        $audits = AuditLog::query()->count();
        $task->update(['progress' => 10, 'estimated_sessions' => 6]);
        $this->assertSame($audits, AuditLog::query()->count());

        // Bỏ ngày bắt đầu: không còn trên lịch. Đặt lại: lên lịch lại.
        $task->update(['status' => TaskStatus::Todo, 'start_date' => null]);
        $this->assertSame(0, ScheduleEntry::query()->count());

        $task->update(['start_date' => '2026-10-12']);
        $this->assertSame('2026-10-12', ScheduleEntry::query()->sole()->starts_at->toDateString());

        // Xoá công việc: lịch cũng mất.
        $task->delete();
        $this->assertSame(0, ScheduleEntry::query()->count());

        // Công việc đã huỷ từ đầu thì không tạo lịch "Đã huỷ".
        $this->makeTask(['start_date' => '2026-10-12', 'status' => TaskStatus::Cancelled]);
        $this->assertSame(0, ScheduleEntry::query()->count());
    }

    public function test_crew_is_notified_when_the_task_is_scheduled_changed_or_cancelled(): void
    {
        $titles = fn (User $user): array => $user->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();

        $this->actingAs($this->pm);

        $task = $this->makeTask([
            'name' => 'Livestream ra mắt',
            'assignee_id' => $this->quay->getKey(),
            'crew_ids' => [$this->sang->getKey(), $this->pm->getKey()],
            'start_date' => '2026-10-08',
            'is_all_day' => false,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location' => 'Studio A',
        ]);

        $this->assertCount(1, $titles($this->quay));
        $this->assertCount(1, $titles($this->sang));
        $this->assertStringContainsString('Livestream ra mắt — 08/10/2026 08:00 – 12:00 tại Studio A', $this->quay->notifications()->first()->data['body']);
        // Người thao tác không tự nhận thông báo của chính mình.
        $this->assertCount(0, $titles($this->pm));

        // Đổi giờ: người ở lại được báo đổi; người bị rút khỏi ê-kíp được báo riêng.
        $task->update(['start_time' => '13:00', 'end_time' => '17:00', 'crew_ids' => []]);

        $this->assertCount(2, $titles($this->quay));
        $this->assertStringContainsString('Đổi thời gian', $this->quay->notifications()->get()->sortBy('created_at')->last()->data['body']);
        $this->assertCount(2, $titles($this->sang));

        // Huỷ công việc: ê-kíp còn lại được báo huỷ.
        $task->update(['status' => TaskStatus::Cancelled]);

        $this->assertCount(3, $titles($this->quay));
        $this->assertCount(2, $titles($this->sang));

        // Công việc đã qua không tạo thông báo.
        $this->makeTask(['assignee_id' => $this->sang->getKey(), 'start_date' => '2026-10-01']);
        $this->assertCount(2, $titles($this->sang));
    }

    /**
     * Dữ liệu của bản trước: lịch đã được "ghi giờ công" thì được giữ lại để
     * tra cứu, dù công việc bỏ ngày hay bị xoá.
     */
    public function test_a_schedule_with_time_logged_by_an_older_version_is_kept(): void
    {
        $task = $this->makeTask(['name' => 'Quay TVC', 'start_date' => '2026-10-06', 'is_all_day' => false, 'start_time' => '08:00', 'end_time' => '12:00']);
        $entry = $task->schedule;
        // Dòng công chấm tay từ lịch của bản trước (không phải dòng tự sinh).
        $task->timesheets()->update(['is_auto' => false]);
        $entry->timesheets()->attach($task->timesheets()->sole()->getKey());

        $task->update(['location' => 'Studio B']);

        $entry->refresh();
        $this->assertSame('2026-10-06 08:00:00', $entry->starts_at->toDateTimeString());
        $this->assertSame('Studio B', $entry->location);

        $task->update(['start_date' => null]);
        $this->assertSame(1, ScheduleEntry::query()->count());

        $task->delete();
        $this->assertSame(1, ScheduleEntry::query()->count());
        $this->assertNull($entry->refresh()->task_id);
    }

    public function test_a_schedule_changed_by_code_writes_back_to_its_task_without_looping(): void
    {
        $manager = app(ScheduleManager::class);
        $task = $this->makeTask(['name' => 'Chụp ảnh', 'start_date' => '2026-10-08']);
        $entry = $task->schedule;

        // Sửa thẳng lịch qua ScheduleManager (tích hợp, seeder): công việc nhận lại đúng thông tin đó.
        $manager->save($entry, [
            'title' => 'Chụp ảnh sản phẩm',
            'schedule_type_id' => $entry->schedule_type_id,
            'status' => ScheduleStatus::Tentative,
            'starts_at' => CarbonImmutable::parse('2026-10-09 09:00'),
            'ends_at' => CarbonImmutable::parse('2026-10-09 11:30'),
            'is_all_day' => false,
            'location' => 'Studio C',
        ], [$this->sang->getKey(), $this->quay->getKey()], $this->pm);

        $task->refresh();

        $this->assertSame(1, ScheduleEntry::query()->count());
        $this->assertSame(1, Task::query()->count());
        $this->assertSame('Chụp ảnh sản phẩm', $task->name);
        $this->assertSame('2026-10-09', $task->start_date->toDateString());
        $this->assertFalse($task->is_all_day);
        $this->assertSame(['09:00', '11:30'], [$task->start_time, $task->end_time]);
        $this->assertSame('Studio C', $task->location);
        $this->assertTrue($task->is_tentative);
        // Quay vẫn là người phụ trách; Sáng thành người trong ê-kíp.
        $this->assertSame($this->quay->getKey(), $task->assignee_id);
        $this->assertSame([$this->sang->getKey()], $task->crew_ids);

        // Lưu lại công việc: lịch giữ nguyên, không lệch nhau.
        $task->update(['description' => 'Nền trắng']);

        $entry->refresh();
        $this->assertSame('2026-10-09 09:00:00', $entry->starts_at->toDateTimeString());
        $this->assertSame(ScheduleStatus::Tentative, $entry->status);
        $this->assertSame('Nền trắng', $entry->notes);
    }

    public function test_task_form_carries_the_schedule_fields_and_planners_only_change_them(): void
    {
        $this->actingAs($this->pm);

        Livewire::test(ManageTasks::class)
            ->callAction('create', data: [
                'name' => 'Vận hành màn hình LED',
                'assignee_id' => $this->quay->getKey(),
                'start_date' => '2026-10-10',
                'due_date' => '2026-10-11',
                'crew_ids' => [$this->sang->getKey()],
                'location' => 'Nhà văn hoá Thanh Niên',
                'is_tentative' => true,
                'budget' => 4000000,
            ])
            ->assertHasNoActionErrors();

        $task = Task::query()->sole();
        $entry = $task->schedule;

        // Loại lịch mặc định là loại đầu danh sách; không nhập giờ thì cả ngày.
        $this->assertSame(ScheduleType::query()->active()->where('is_leave', false)->ordered()->value('id'), $entry->schedule_type_id);
        $this->assertSame('10/10 – 11/10 (cả ngày)', $entry->timeLabel());
        $this->assertSame(ScheduleStatus::Tentative, $entry->status);
        $this->assertSame('4000000.00', $task->budget);

        // Người được giao (và người trong ê-kíp) chỉ đổi được trạng thái, tiến độ; lịch đi theo.
        $this->actingAs($this->sang);

        $this->assertTrue($this->sang->can('update', $task));

        Livewire::test(ManageTasks::class)
            ->set('activeTab', 'all')
            ->callTableAction('edit', $task, data: [
                'status' => TaskStatus::Done->value,
                'location' => 'Sửa trộm',
            ])
            ->assertHasNoTableActionErrors();

        $task->refresh();
        $entry->refresh();

        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertSame('2026-10-10', $task->start_date->toDateString());
        $this->assertSame('Nhà văn hoá Thanh Niên', $task->location);
        $this->assertSame(ScheduleStatus::Done, $entry->status);
        $this->assertSame('2026-10-10', $entry->starts_at->toDateString());
    }

    public function test_tasks_show_on_their_schedule_tab_and_the_calendar(): void
    {
        $task = $this->makeTask(['name' => 'Quay bài giảng 1–3', 'start_date' => '2026-10-08']);
        $other = $this->makeTask(['name' => 'Việc khác', 'start_date' => '2026-10-08']);

        $this->actingAs($this->pm);

        Livewire::test(ScheduleRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertCanSeeTableRecords([$task->schedule])
            ->assertCanNotSeeTableRecords([$other->schedule]);

        $this->get(WorkCalendar::getUrl(['date' => '2026-10-08']))
            ->assertOk()
            ->assertSee('Quay bài giảng 1–3');
    }

    public function test_long_all_day_tasks_show_compactly_and_do_not_count_as_conflicts(): void
    {
        $conflicts = app(ScheduleConflicts::class);

        // Hậu kỳ 12 ngày (cả ngày) và một buổi quay nằm giữa, cùng một người.
        $post = $this->makeTask(['name' => 'Hậu kỳ phim', 'start_date' => '2026-10-05', 'due_date' => '2026-10-16'])->schedule;
        $shoot = $this->makeTask(['name' => 'Quay TVC', 'start_date' => '2026-10-08', 'is_all_day' => false, 'start_time' => '08:00', 'end_time' => '12:00'])->schedule;

        $this->assertTrue($post->isBackground());
        $this->assertFalse($shoot->isBackground());
        $this->assertSame([], $conflicts->forEntry($post));
        $this->assertSame([], $conflicts->forEntry($shoot));

        // Trên lịch tuần: việc dài ngày nằm ở dải đầu ngày, không chiếm buổi sáng.
        $week = app(CalendarQuery::class)->week(Carbon::parse('2026-10-08'));
        $thursday = collect($week['days'])->first(fn (array $day): bool => $day['date']->toDateString() === '2026-10-08');

        $this->assertSame(['Hậu kỳ phim'], array_map(fn (ScheduleEntry $entry): string => $entry->title, $thursday['long']));
        $this->assertSame(['Quay TVC'], array_map(fn (ScheduleEntry $entry): string => $entry->title, $thursday['morning']));
        $this->assertSame([], $week['conflicts']);

        // Việc cả ngày tới 3 ngày (đi quay xa) vẫn giữ chỗ và báo trùng.
        $trip = $this->makeTask(['name' => 'Quay ngoại cảnh', 'start_date' => '2026-10-08', 'due_date' => '2026-10-10'])->schedule;

        $this->assertFalse($trip->isBackground());
        $this->assertSame(['Quay: trùng "Quay TVC" (08/10 · 08:00 – 12:00)'], $conflicts->describe($conflicts->forEntry($trip)));
    }

    public function test_backfill_links_old_schedules_and_schedules_old_tasks(): void
    {
        // Dữ liệu của bản trước: lịch có công việc do hệ thống tạo, nhưng chưa đánh dấu là lịch của công việc,
        // và công việc chưa có thông tin lịch.
        $legacyTask = TaskSchedule::withoutSync(fn (): Task => Task::factory()->create([
            'name' => 'Livestream cũ', 'assignee_id' => $this->quay->getKey(), 'start_date' => '2026-10-09', 'due_date' => '2026-10-09',
            'status' => TaskStatus::Done,
        ]));
        $legacyTask->forceFill(['from_schedule' => true])->saveQuietly();
        $legacyEntry = $this->makeEntry('2026-10-09 13:00', '2026-10-09 17:00', [$this->quay, $this->sang], ['title' => 'Livestream cũ', 'location' => 'Studio A', 'notes' => 'Khách gửi kịch bản trước']);
        ScheduleEntry::query()->whereKey($legacyEntry->getKey())->update(['task_id' => $legacyTask->getKey()]);

        // Công việc tạo tay từ trước, có ngày bắt đầu nhưng chưa có lịch.
        $manual = TaskSchedule::withoutSync(fn (): Task => Task::factory()->create([
            'name' => 'Bảo dưỡng máy quay', 'assignee_id' => $this->sang->getKey(), 'start_date' => '2026-10-12',
        ]));
        $undated = TaskSchedule::withoutSync(fn (): Task => Task::factory()->create());

        $this->assertSame(['linked' => 1, 'created' => 1], app(TaskSchedule::class)->backfill());
        // Chạy lại không làm gì thêm.
        $this->assertSame(['linked' => 0, 'created' => 0], app(TaskSchedule::class)->backfill());

        // Lịch cũ thành lịch của công việc; công việc nhận giờ, ê-kíp, địa điểm của lịch.
        $legacyTask->refresh();
        $this->assertTrue($legacyEntry->refresh()->from_task);
        $this->assertFalse($legacyTask->is_all_day);
        $this->assertSame(['13:00', '17:00'], [$legacyTask->start_time, $legacyTask->end_time]);
        $this->assertSame([$this->sang->getKey()], $legacyTask->crew_ids);
        $this->assertSame('Studio A', $legacyTask->location);
        $this->assertSame('2026-10-09 13:00:00', $legacyEntry->starts_at->toDateTimeString());
        // Ghi chú của lịch không mất, và lịch nhận trạng thái của công việc.
        $this->assertSame('Khách gửi kịch bản trước', $legacyTask->description);
        $this->assertSame('Khách gửi kịch bản trước', $legacyEntry->notes);
        $this->assertSame(ScheduleStatus::Done, $legacyEntry->status);

        // Công việc tạo tay có lịch cả ngày; công việc không có ngày thì không.
        $this->assertSame('2026-10-12', $manual->schedule->starts_at->toDateString());
        $this->assertNull($undated->schedule);
        $this->assertSame(2, ScheduleEntry::query()->count());

        // Nối dữ liệu cũ không gửi thông báo cho ai.
        $this->assertSame(0, $this->quay->notifications()->count() + $this->sang->notifications()->count());
    }

    /**
     * Một việc độc lập giao cho Quay.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(array $attributes = []): Task
    {
        return Task::factory()->create([
            'assignee_id' => $this->quay->getKey(),
            ...$attributes,
        ])->refresh();
    }
}
