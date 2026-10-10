<?php

namespace Tests\Feature;

use App\Enums\ScheduleStatus;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Models\AuditLog;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Notifications\ReminderService;
use App\Services\Scheduling\CalendarQuery;
use App\Services\Scheduling\ScheduleConflicts;
use App\Services\Scheduling\ScheduleManager;
use App\Services\Scheduling\ScheduleTimesheets;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsJobs;
use Tests\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Lịch làm việc: thời gian, trùng lịch, quyền, thông báo, cách sắp lịch theo
 * tuần / tháng / người, ghi giờ công và nhắc lịch.
 */
class ScheduleTest extends TestCase
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
        $this->quay = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Quay', 'hourly_rate' => 150000]);
        $this->sang = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Sáng', 'hourly_rate' => 100000]);
    }

    public function test_default_schedule_types_exist_with_one_leave_type(): void
    {
        $this->assertGreaterThanOrEqual(6, ScheduleType::query()->count());
        $this->assertSame('Nghỉ phép / bận', ScheduleType::leave()?->name);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', ScheduleType::query()->first()->hex());
    }

    public function test_time_labels_and_all_day_normalisation(): void
    {
        $short = $this->makeEntry('2026-10-08 08:00', '2026-10-08 11:30');
        $overnight = $this->makeEntry('2026-10-08 14:00', '2026-10-09 18:00');
        $allDay = $this->makeEntry('2026-10-08 10:15', '2026-10-08 10:15', attributes: ['is_all_day' => true]);
        $trip = $this->makeEntry('2026-10-12 00:00', '2026-10-14 00:00', attributes: ['is_all_day' => true]);

        $this->assertSame('08:00 – 11:30', $short->timeLabel());
        $this->assertSame('08/10 14:00 – 09/10 18:00', $overnight->timeLabel());
        $this->assertSame('Từ 14:00', $overnight->timeLabelOn(Carbon::parse('2026-10-08')));
        $this->assertSame('Đến 18:00', $overnight->timeLabelOn(Carbon::parse('2026-10-09')));

        // Lịch cả ngày phủ trọn ngày, dù nhập giờ gì.
        $this->assertSame('2026-10-08 00:00:00', $allDay->starts_at->toDateTimeString());
        $this->assertSame('2026-10-08 23:59:59', $allDay->ends_at->toDateTimeString());
        $this->assertSame('Cả ngày', $allDay->timeLabel());
        $this->assertSame('12/10 – 14/10 (cả ngày)', $trip->timeLabel());
        $this->assertTrue($trip->isMultiDay());
    }

    public function test_overlapping_entries_for_the_same_person_are_conflicts(): void
    {
        $conflicts = app(ScheduleConflicts::class);

        $trip = $this->makeEntry('2026-10-07 06:00', '2026-10-08 18:00', [$this->quay, $this->sang], ['title' => 'Quay Đà Lạt']);
        $shoot = $this->makeEntry('2026-10-08 09:00', '2026-10-08 12:00', [$this->sang], ['title' => 'Chụp sản phẩm']);

        // Sáng bị trùng; Quay không ở buổi chụp nên không trùng.
        $found = $conflicts->forEntry($shoot);

        $this->assertCount(1, $found);
        $this->assertTrue($found[0]['user']->is($this->sang));
        $this->assertTrue($found[0]['entry']->is($trip));
        $this->assertSame(['Sáng: trùng "Quay Đà Lạt" (07/10 06:00 – 08/10 18:00)'], $conflicts->describe($found));

        // Hai việc nối tiếp nhau (một việc kết thúc đúng lúc việc kia bắt đầu) không phải là trùng.
        $this->assertSame([], $conflicts->find([$this->sang->getKey()], Carbon::parse('2026-10-08 18:00'), Carbon::parse('2026-10-08 20:00')));

        // Lịch đã huỷ không giữ chỗ.
        $trip->update(['status' => ScheduleStatus::Cancelled]);

        $this->assertSame([], $conflicts->forEntry($shoot->refresh()));
    }

    public function test_being_scheduled_during_leave_is_reported_as_leave(): void
    {
        $conflicts = app(ScheduleConflicts::class);

        app(ScheduleManager::class)->registerLeave($this->sang, Carbon::parse('2026-10-10')->startOfDay(), Carbon::parse('2026-10-10')->endOfDay());

        $job = $this->makeEntry('2026-10-10 07:00', '2026-10-10 12:00', [$this->sang, $this->quay], ['title' => 'Livestream']);

        $this->assertSame(['Sáng: đang nghỉ (10/10, cả ngày)'], $conflicts->describe($conflicts->forEntry($job)));

        // Trong một tập lịch: chỉ lịch việc bị đánh dấu, kèm tên người bị trùng.
        $marked = $conflicts->among(app(CalendarQuery::class)->entries(Carbon::parse('2026-10-10')->startOfDay(), Carbon::parse('2026-10-10')->endOfDay()));

        $this->assertSame([$job->getKey() => ['Sáng']], $marked);
    }

    public function test_only_planners_can_schedule_but_anyone_can_manage_their_own_leave(): void
    {
        $manager = app(ScheduleManager::class);
        $type = ScheduleType::query()->where('is_leave', false)->first();
        $attributes = [
            'title' => 'Quay phim',
            'schedule_type_id' => $type->getKey(),
            'starts_at' => Carbon::parse('2026-10-08 08:00'),
            'ends_at' => Carbon::parse('2026-10-08 12:00'),
        ];

        // Nhân viên không xếp được lịch việc.
        try {
            $manager->save(null, $attributes, [$this->quay->getKey()], $this->quay);
            $this->fail('Nhân viên không được xếp lịch.');
        } catch (AuthorizationException) {
        }

        $entry = $manager->save(null, $attributes, [$this->quay->getKey()], $this->pm);

        $this->assertSame($this->pm->getKey(), $entry->created_by);
        $this->assertTrue($this->quay->cannot('update', $entry));
        $this->assertTrue($this->quay->cannot('delete', $entry));
        $this->assertTrue($this->quay->can('view', $entry));

        // Nhưng tự đăng ký nghỉ, tự sửa và tự xoá lịch nghỉ của mình thì được.
        $leave = $manager->registerLeave($this->quay, Carbon::parse('2026-10-12')->startOfDay(), Carbon::parse('2026-10-12')->endOfDay(), note: 'Việc riêng');

        $this->assertTrue($leave->isLeave());
        $this->assertSame('Nghỉ: Quay', $leave->title);
        $this->assertSame([$this->quay->getKey()], $leave->crew->pluck('id')->all());
        $this->assertTrue($this->quay->can('update', $leave));
        $this->assertTrue($this->sang->cannot('update', $leave));
        $this->assertTrue($this->sang->cannot('delete', $leave));

        // Khi tự sửa, không đổi được loại lịch hay người nghỉ — chỉ đổi thời gian và ghi chú.
        $leave = $manager->save($leave, [
            'title' => $leave->title,
            'schedule_type_id' => $type->getKey(),
            'starts_at' => Carbon::parse('2026-10-13')->startOfDay(),
            'ends_at' => Carbon::parse('2026-10-13')->endOfDay(),
            'is_all_day' => true,
            'notes' => 'Dời sang thứ Ba',
        ], [$this->sang->getKey()], $this->quay);

        $this->assertTrue($leave->isLeave());
        $this->assertSame([$this->quay->getKey()], $leave->crew->pluck('id')->all());
        $this->assertSame('2026-10-13', $leave->starts_at->toDateString());

        $manager->delete($leave, $this->quay);

        $this->assertModelMissing($leave);
    }

    public function test_invalid_times_are_refused(): void
    {
        $manager = app(ScheduleManager::class);
        $type = ScheduleType::query()->where('is_leave', false)->first();
        $base = ['title' => 'Quay phim', 'schedule_type_id' => $type->getKey()];

        foreach ([
            ['2026-10-08 12:00', '2026-10-08 08:00', 'Giờ kết thúc phải sau giờ bắt đầu.'],
            ['2026-10-08 08:00', '2026-10-08 08:00', 'Giờ kết thúc phải sau giờ bắt đầu.'],
            ['2026-10-08 08:00', '2027-01-08 08:00', 'Một lịch không kéo dài quá 2 tháng. Hãy tách thành nhiều lịch.'],
        ] as [$from, $to, $message]) {
            try {
                $manager->save(null, [...$base, 'starts_at' => Carbon::parse($from), 'ends_at' => Carbon::parse($to)], [], $this->pm);
                $this->fail("Phải từ chối lịch {$from} → {$to}.");
            } catch (WorkflowException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, ScheduleEntry::query()->count());
    }

    public function test_crew_is_notified_about_new_changed_and_cancelled_schedules(): void
    {
        $manager = app(ScheduleManager::class);
        $type = ScheduleType::query()->where('is_leave', false)->first();
        $attributes = [
            'title' => 'Livestream ra mắt',
            'schedule_type_id' => $type->getKey(),
            'starts_at' => Carbon::parse('2026-10-08 08:00'),
            'ends_at' => Carbon::parse('2026-10-08 12:00'),
            'location' => 'Studio A',
        ];
        $titles = fn (User $user): array => $user->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();

        // Tạo: Quay và người xếp lịch đều trong ê-kíp — người xếp lịch không tự nhận thông báo.
        $entry = $manager->save(null, $attributes, [$this->quay->getKey(), $this->pm->getKey()], $this->pm);

        $this->assertSame(['Bạn có lịch mới'], $titles($this->quay));
        $this->assertSame([], $titles($this->pm));
        $this->assertStringContainsString('Livestream ra mắt — 08/10/2026 08:00 – 12:00 tại Studio A', $this->quay->notifications()->first()->data['body']);
        $this->assertStringContainsString('/admin/calendar', $this->quay->notifications()->first()->data['actions'][0]['url']);

        // Đổi giờ và thay người: Quay (ở lại) được báo đổi giờ, Sáng (mới) được báo có lịch.
        $this->travel(1)->seconds();
        $entry = $manager->save($entry, [...$attributes, 'starts_at' => Carbon::parse('2026-10-08 13:00'), 'ends_at' => Carbon::parse('2026-10-08 17:00')], [$this->quay->getKey(), $this->sang->getKey()], $this->pm);

        $this->assertSame(['Bạn có lịch mới', 'Lịch của bạn đã thay đổi'], $titles($this->quay));
        $this->assertSame(['Bạn có lịch mới'], $titles($this->sang));
        $this->assertStringContainsString('Đổi thời gian', $this->quay->notifications()->get()->firstWhere('data.title', 'Lịch của bạn đã thay đổi')->data['body']);

        // Rút Sáng khỏi lịch.
        $this->travel(1)->seconds();
        $entry = $manager->save($entry, [...$attributes, 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at], [$this->quay->getKey()], $this->pm);

        $this->assertSame(['Bạn có lịch mới', 'Bạn không còn trong lịch'], $titles($this->sang));

        // Lưu lại mà không đổi gì thì không báo thêm.
        $manager->save($entry, [...$attributes, 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at], [$this->quay->getKey()], $this->pm);

        $this->assertCount(2, $titles($this->quay));

        // Huỷ lịch.
        $this->travel(1)->seconds();
        $manager->save($entry, [...$attributes, 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at, 'status' => ScheduleStatus::Cancelled], [$this->quay->getKey()], $this->pm);

        $this->assertSame('Lịch đã bị huỷ', collect($titles($this->quay))->last());

        // Lịch đã qua (nhập bù) không tạo thông báo.
        $before = $this->quay->notifications()->count();
        $manager->save(null, [...$attributes, 'starts_at' => Carbon::parse('2026-10-01 08:00'), 'ends_at' => Carbon::parse('2026-10-01 12:00')], [$this->quay->getKey()], $this->pm);

        $this->assertSame($before, $this->quay->notifications()->count());
    }

    public function test_planners_are_told_when_someone_registers_leave(): void
    {
        $director = $this->createUserWithRole(SystemRole::Director);

        app(ScheduleManager::class)->registerLeave($this->quay, Carbon::parse('2026-10-12')->startOfDay(), Carbon::parse('2026-10-13')->endOfDay(), note: 'Về quê');

        foreach ([$this->pm, $director] as $planner) {
            $notification = $planner->notifications()->sole();

            $this->assertSame('Đăng ký nghỉ', $notification->data['title']);
            $this->assertStringContainsString('Quay: 12/10 – 13/10 (cả ngày) — Về quê', $notification->data['body']);
        }

        $this->assertSame(0, $this->sang->notifications()->count());
    }

    public function test_week_view_splits_days_into_morning_and_afternoon(): void
    {
        $morning = $this->makeEntry('2026-10-05 08:30', '2026-10-05 10:00', [$this->quay]);
        $afternoon = $this->makeEntry('2026-10-05 13:00', '2026-10-05 18:00', [$this->quay]);
        $trip = $this->makeEntry('2026-10-07 14:00', '2026-10-08 18:00', [$this->sang]);
        $cancelled = $this->makeEntry('2026-10-06 08:00', '2026-10-06 09:00', attributes: ['status' => ScheduleStatus::Cancelled]);
        $nextWeek = $this->makeEntry('2026-10-12 08:00', '2026-10-12 09:00');
        app(ScheduleManager::class)->registerLeave($this->quay, Carbon::parse('2026-10-10')->startOfDay(), Carbon::parse('2026-10-10')->endOfDay());

        $week = app(CalendarQuery::class)->week(Carbon::parse('2026-10-07'));
        $ids = fn (array $entries): array => array_map(fn (ScheduleEntry $entry): int => $entry->getKey(), $entries);

        $this->assertSame('05/10 – 11/10/2026', $week['label']);
        $this->assertCount(7, $week['days']);
        $this->assertSame(['Thứ 2', 'Chủ nhật'], [$week['days'][0]['weekday'], $week['days'][6]['weekday']]);
        $this->assertTrue($week['days'][2]['isToday']);

        $this->assertSame([$morning->getKey()], $ids($week['days'][0]['morning']));
        $this->assertSame([$afternoon->getKey()], $ids($week['days'][0]['afternoon']));

        // Lịch qua đêm: ngày đầu ở buổi chiều (bắt đầu 14:00), ngày sau ở buổi sáng (tiếp tục).
        $this->assertSame([$trip->getKey()], $ids($week['days'][2]['afternoon']));
        $this->assertSame([$trip->getKey()], $ids($week['days'][3]['morning']));

        // Lịch đã huỷ ẩn theo mặc định; lịch nghỉ nằm ở dải riêng.
        $this->assertSame([], $ids($week['days'][1]['morning']));
        $this->assertCount(1, $week['days'][5]['leave']);
        $this->assertSame([], $ids($week['days'][5]['morning']));

        $withCancelled = app(CalendarQuery::class)->week(Carbon::parse('2026-10-07'), ['cancelled' => true]);

        $this->assertSame([$cancelled->getKey()], $ids($withCancelled['days'][1]['morning']));

        // Lọc theo người.
        $onlySang = app(CalendarQuery::class)->week(Carbon::parse('2026-10-07'), ['user' => $this->sang->getKey()]);

        $this->assertSame([], $ids($onlySang['days'][0]['morning']));
        $this->assertSame([$trip->getKey()], $ids($onlySang['days'][2]['afternoon']));

        $this->assertFalse(collect($week['days'])->flatMap(fn (array $day): array => [...$day['morning'], ...$day['afternoon']])->contains('id', $nextWeek->getKey()));
    }

    public function test_month_people_and_agenda_views(): void
    {
        $job = $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->quay, $this->sang]);
        $trip = $this->makeEntry('2026-10-09 06:00', '2026-10-10 18:00', [$this->quay]);
        $calendar = app(CalendarQuery::class);

        // Tháng 10/2026: lưới bắt đầu từ thứ Hai 28/09 và kết thúc Chủ nhật 01/11.
        $month = $calendar->month(Carbon::parse('2026-10-07'));

        $this->assertSame('Tháng 10/2026', $month['label']);
        $this->assertCount(5, $month['weeks']);
        $this->assertSame('2026-09-28', $month['weeks'][0][0]['date']->toDateString());
        $this->assertFalse($month['weeks'][0][0]['inMonth']);
        $this->assertSame('2026-11-01', $month['weeks'][4][6]['date']->toDateString());

        $day = collect($month['weeks'])->flatten(1)->firstWhere(fn (array $day): bool => $day['date']->toDateString() === '2026-10-10');

        $this->assertSame([$trip->getKey()], array_map(fn (ScheduleEntry $entry): int => $entry->getKey(), $day['entries']));

        // Theo người: người có lịch xếp trước; mỗi ô là lịch của người đó trong ngày.
        $people = $calendar->people(Carbon::parse('2026-10-07'));
        $rows = collect($people['rows'])->keyBy(fn (array $row): int => $row['user']->getKey());

        $this->assertSame(2, $rows[$this->quay->getKey()]['total']);
        $this->assertSame(1, $rows[$this->sang->getKey()]['total']);
        $this->assertSame(0, $rows[$this->pm->getKey()]['total']);
        $this->assertCount(1, $rows[$this->quay->getKey()]['cells']['2026-10-10']);
        $this->assertSame([], $rows[$this->sang->getKey()]['cells']['2026-10-10']);
        $this->assertTrue($people['rows'][0]['busy']);
        $this->assertFalse(collect($people['rows'])->last()['busy']);

        // Lịch của tôi: gom theo ngày, bỏ ngày trống; lịch nhiều ngày có mặt ở từng ngày.
        $agenda = $calendar->agenda($this->quay, Carbon::parse('2026-10-07'), 30);

        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], array_map(fn (array $day): string => $day['date']->toDateString(), $agenda));
        $this->assertSame([$job->getKey()], array_map(fn (ScheduleEntry $entry): int => $entry->getKey(), $agenda[0]['entries']));
        $this->assertSame([], $calendar->agenda($this->pm, Carbon::parse('2026-10-07'), 30));
    }

    public function test_timesheets_are_logged_once_from_a_finished_schedule(): void
    {
        $service = app(ScheduleTimesheets::class);

        // Hôm qua, 08:00 – 12:30: gợi ý 4,5 giờ.
        $entry = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:30', [$this->quay, $this->sang], ['title' => 'Quay TVC']);

        $this->assertSame('4.5', $service->suggestedHours($entry));
        $this->assertSame(2, $service->log($entry, $this->pm, '4.5'));

        // Chi phí nhân sự: 4,5 × 150.000 + 4,5 × 100.000.
        $this->assertEquals(1125000, Timesheet::query()->sum('cost'));
        $this->assertSame(ScheduleStatus::Done, $entry->refresh()->status);
        $this->assertNotNull($entry->timesheets_logged_at);
        $this->assertSame('Theo lịch: Quay TVC', Timesheet::query()->first()->description);

        // Ghi lại không tạo trùng; thêm người vào ê-kíp rồi ghi thì chỉ ghi cho người mới.
        $this->assertSame(0, $service->log($entry, $this->pm, '4.5'));

        $entry->crew()->attach($this->pm->getKey());

        $this->assertSame(1, $service->log($entry->refresh(), $this->pm, 2));
        $this->assertSame(3, Timesheet::query()->count());

        // Lịch nhiều ngày: mỗi ngày đã qua một dòng cho mỗi người, 8 giờ gợi ý.
        $trip = $this->makeEntry('2026-10-05 06:00', '2026-10-06 18:00', [$this->quay]);

        $this->assertSame('8', $service->suggestedHours($trip));
        $this->assertSame(2, $service->log($trip, $this->pm, 8));
        $this->assertSame(['2026-10-05', '2026-10-06'], $trip->timesheets()->orderBy('work_date')->get()->map(fn (Timesheet $t): string => $t->work_date->toDateString())->all());
    }

    public function test_timesheets_of_a_schedule_without_a_task_are_overhead(): void
    {
        $entry = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:00', [$this->quay, $this->sang]);

        $this->assertSame(2, app(ScheduleTimesheets::class)->log($entry, $this->pm, 4));

        $timesheets = $entry->timesheets()->get();

        $this->assertSame([null, null], $timesheets->pluck('task_id')->all());
        // 4 giờ × 150.000 và 4 giờ × 100.000: vẫn là chi phí nhân sự, nhưng không vào công việc nào.
        $this->assertEqualsCanonicalizing(['600000.00', '400000.00'], $timesheets->pluck('cost')->all());
        $this->assertSame(ScheduleStatus::Done, $entry->refresh()->status);
    }

    public function test_timesheets_cannot_be_logged_too_early_or_for_leave_or_without_permission(): void
    {
        $service = app(ScheduleTimesheets::class);

        $future = $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->quay]);
        $leave = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:00', [$this->quay], ['schedule_type_id' => ScheduleType::leave()->getKey()]);
        $past = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:00', [$this->quay]);

        foreach ([[$future, 'Lịch chưa diễn ra nên chưa ghi giờ công được.'], [$leave, 'Lịch nghỉ / bận không ghi giờ công.']] as [$entry, $message]) {
            try {
                $service->log($entry, $this->pm, 4);
                $this->fail('Phải từ chối ghi giờ công.');
            } catch (WorkflowException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        // Nhân viên không ghi giờ công cho người khác.
        $this->expectException(AuthorizationException::class);

        $service->log($past, $this->quay, 4);
    }

    public function test_a_schedule_with_timesheets_cannot_be_deleted(): void
    {
        $entry = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:00', [$this->quay]);

        app(ScheduleTimesheets::class)->log($entry, $this->pm, 4);

        try {
            app(ScheduleManager::class)->delete($entry, $this->pm);
            $this->fail('Lịch đã ghi giờ công không được xoá.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('đã ghi giờ công', $exception->getMessage());
        }

        $this->assertModelExists($entry);
    }

    public function test_tomorrows_schedules_are_reminded_once_per_person(): void
    {
        $tomorrow = $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->quay, $this->sang], ['title' => 'Livestream']);
        $this->makeEntry('2026-10-09 08:00', '2026-10-09 12:00', [$this->quay]);
        $this->makeEntry('2026-10-08 13:00', '2026-10-08 15:00', [$this->quay], ['status' => ScheduleStatus::Cancelled]);
        app(ScheduleManager::class)->registerLeave($this->pm, Carbon::parse('2026-10-08')->startOfDay(), Carbon::parse('2026-10-08')->endOfDay());

        $reminders = app(ReminderService::class);

        $this->assertSame(2, $reminders->schedulesTomorrow());
        $this->assertSame(0, $reminders->schedulesTomorrow());

        $notification = $this->quay->notifications()->get()->firstWhere('data.title', 'Nhắc lịch ngày mai');

        $this->assertStringContainsString('Livestream — 08/10/2026 08:00 – 12:00', $notification->data['body']);
        $this->assertNull($this->pm->notifications()->get()->firstWhere('data.title', 'Nhắc lịch ngày mai'));

        // Dời lịch sang ngày kia: tới hôm đó sẽ được nhắc lại.
        $tomorrow->update(['starts_at' => '2026-10-09 08:00', 'ends_at' => '2026-10-09 12:00']);
        $this->travel(1)->days();

        $this->assertSame(3, $reminders->schedulesTomorrow());
    }

    public function test_schedule_changes_are_audited_without_leaking_calendar_tokens(): void
    {
        $manager = app(ScheduleManager::class);
        $type = ScheduleType::query()->where('is_leave', false)->first();
        $attributes = [
            'title' => 'Quay phim',
            'schedule_type_id' => $type->getKey(),
            'starts_at' => Carbon::parse('2026-10-08 08:00'),
            'ends_at' => Carbon::parse('2026-10-08 12:00'),
        ];

        $entry = $manager->save(null, $attributes, [$this->quay->getKey()], $this->pm);
        $manager->save($entry, $attributes, [$this->sang->getKey()], $this->pm);

        $logs = AuditLog::query()->where('auditable_type', $entry->getMorphClass())->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
        $this->assertSame('Dự', $logs[0]->user_name);
        $this->assertSame('Lịch "Quay phim" (08/10/2026)', $logs[0]->label);
        $this->assertSame(['Ê-kíp' => 'Quay'], $logs[1]->old_values);
        $this->assertSame(['Ê-kíp' => 'Sáng'], $logs[1]->new_values);

        // Mã đường dẫn lịch cá nhân là bí mật: không nằm trong nhật ký và không lộ khi xuất model.
        $token = $this->quay->calendarToken();

        $this->assertSame(48, strlen($token));
        $this->assertArrayNotHasKey('calendar_token', $this->quay->refresh()->toArray());
        $this->assertStringNotContainsString($token, (string) json_encode(AuditLog::query()->get()->toArray()));
    }
}
