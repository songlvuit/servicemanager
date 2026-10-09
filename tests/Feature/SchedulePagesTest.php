<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ScheduleStatus;
use App\Enums\SystemRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\WorkCalendar;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\ScheduleTypes\Pages\ManageScheduleTypes;
use App\Filament\Resources\ScheduleTypes\ScheduleTypeResource;
use App\Filament\Support\Scheduling\ScheduleForm;
use App\Filament\Widgets\MySchedule;
use App\Models\AuditLog;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\PublicCalendarLink;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsProjects;
use Tests\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * Màn hình Lịch làm việc, trang chỉ-xem công khai và nguồn iCalendar.
 */
class SchedulePagesTest extends TestCase
{
    use BuildsProjects;
    use BuildsSchedules;
    use RefreshDatabase;

    private User $pm;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        // Thứ Tư 07/10/2026.
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->pm = $this->createUserWithRole(SystemRole::ProjectManager, ['name' => 'Lê Dự']);
        $this->employee = $this->createUserWithRole(SystemRole::Employee, ['name' => 'Ngô Quay', 'hourly_rate' => 150000]);
    }

    public function test_every_role_can_open_the_calendar_in_every_view(): void
    {
        $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['title' => 'Livestream hội thảo', 'location' => 'Studio A']);

        foreach (SystemRole::cases() as $role) {
            $this->actingAs($this->createUserWithRole($role));

            foreach (array_keys(WorkCalendar::VIEWS) as $view) {
                $response = $this->get(WorkCalendar::getUrl(['view' => $view, 'date' => '2026-10-07']))->assertOk();

                if ($view !== 'mine') {
                    $response->assertSee('Livestream hội thảo');
                }
            }
        }

        // Lịch của tôi: chỉ lịch mình tham gia.
        $this->actingAs($this->employee)
            ->get(WorkCalendar::getUrl(['view' => 'mine', 'date' => '2026-10-07']))
            ->assertSee('Livestream hội thảo')
            ->assertSee('Studio A');

        $this->actingAs($this->pm)
            ->get(WorkCalendar::getUrl(['view' => 'mine', 'date' => '2026-10-07']))
            ->assertDontSee('Livestream hội thảo')
            ->assertSee('Bạn chưa có lịch nào trong 30 ngày này.');
    }

    public function test_navigation_moves_by_week_or_month_and_filters_apply(): void
    {
        $livestream = ScheduleType::query()->where('name', 'Livestream')->first();
        $filming = ScheduleType::query()->where('name', 'Quay phim')->first();

        $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['title' => 'Việc tuần này', 'schedule_type_id' => $livestream->getKey()]);
        $this->makeEntry('2026-10-08 13:00', '2026-10-08 15:00', [$this->pm], ['title' => 'Việc quay phim', 'schedule_type_id' => $filming->getKey()]);
        $this->makeEntry('2026-10-15 08:00', '2026-10-15 12:00', [], ['title' => 'Việc tuần sau']);

        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->assertSet('mode', 'week')
            ->assertSet('date', '2026-10-07')
            ->assertSee('05/10 – 11/10/2026')
            ->assertSee('Việc tuần này')
            ->assertDontSee('Việc tuần sau')
            ->call('next')
            ->assertSet('date', '2026-10-14')
            ->assertSee('Việc tuần sau')
            ->assertDontSee('Việc tuần này')
            ->call('goToToday')
            ->assertSet('date', '2026-10-07')
            // Lọc theo loại lịch và theo người.
            ->set('type', (string) $filming->getKey())
            ->assertSee('Việc quay phim')
            ->assertDontSee('Việc tuần này')
            ->call('clearFilters')
            ->set('person', (string) $this->employee->getKey())
            ->assertSee('Việc tuần này')
            ->assertDontSee('Việc quay phim')
            ->call('clearFilters')
            // Sang tháng: nhảy theo tháng; bấm một ngày thì về tuần chứa ngày đó.
            ->call('setMode', 'month')
            ->assertSee('Tháng 10/2026')
            ->call('next')
            ->assertSee('Tháng 11/2026')
            ->call('openDay', '2026-10-15')
            ->assertSet('mode', 'week')
            ->assertSee('12/10 – 18/10/2026')
            // Giá trị lạ trên URL không làm hỏng trang.
            ->call('setMode', 'khong-co')
            ->assertSet('mode', 'week')
            ->set('date', 'abc')
            ->assertOk();
    }

    public function test_planner_creates_edits_and_deletes_a_schedule_from_the_calendar(): void
    {
        $type = ScheduleType::query()->where('name', 'Livestream')->first();
        $project = $this->makeProject(['status' => ProjectStatus::Active]);

        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->assertActionVisible('createEntry')
            ->callAction('createEntry', data: [
                'title' => 'Livestream ra mắt',
                'schedule_type_id' => $type->getKey(),
                'status' => 'confirmed',
                'start_date' => '2026-10-08',
                'end_date' => '2026-10-08',
                'start_time' => '13:30',
                'end_time' => '17:00',
                'is_all_day' => false,
                'crew' => [$this->employee->getKey()],
                'project_id' => $project->getKey(),
                'location' => 'Studio A',
                'equipment' => '3 cam, 2 mic',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Đã thêm lịch.')
            ->assertSee('Livestream ra mắt');

        $entry = ScheduleEntry::query()->sole();

        $this->assertSame('2026-10-08 13:30:00', $entry->starts_at->toDateTimeString());
        $this->assertSame('2026-10-08 17:00:00', $entry->ends_at->toDateTimeString());
        $this->assertSame($project->getKey(), $entry->project_id);
        $this->assertSame($project->customer_id, $entry->customer_id);
        $this->assertSame([$this->employee->getKey()], $entry->crew->pluck('id')->all());
        $this->assertSame($this->pm->getKey(), $entry->created_by);

        // Sửa: đổi thành lịch cả ngày, hai ngày.
        Livewire::test(WorkCalendar::class)
            ->mountAction('editEntry', ['entry' => $entry->getKey()])
            ->assertActionDataSet(['title' => 'Livestream ra mắt', 'start_time' => '13:30', 'crew' => [$this->employee->getKey()]])
            ->setActionData(['is_all_day' => true, 'end_date' => '2026-10-09', 'title' => 'Livestream ra mắt (2 ngày)'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Đã lưu lịch.');

        $entry->refresh();

        $this->assertTrue($entry->is_all_day);
        $this->assertSame('08/10 – 09/10 (cả ngày)', $entry->timeLabel());
        $this->assertSame('Livestream ra mắt (2 ngày)', $entry->title);

        Livewire::test(WorkCalendar::class)
            ->callAction('deleteEntry', arguments: ['entry' => $entry->getKey()])
            ->assertNotified('Đã xoá lịch.');

        $this->assertSame(0, ScheduleEntry::query()->count());
    }

    public function test_the_form_validates_time_and_warns_about_conflicts(): void
    {
        $type = ScheduleType::query()->where('name', 'Livestream')->first();
        $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['title' => 'Quay TVC']);

        $this->actingAs($this->pm);

        $data = [
            'title' => 'Chụp ảnh',
            'schedule_type_id' => $type->getKey(),
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-08',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'crew' => [$this->employee->getKey()],
        ];

        // Giờ sai định dạng bị chặn ngay ở form.
        Livewire::test(WorkCalendar::class)
            ->callAction('createEntry', data: [...$data, 'start_time' => '25:00'])
            ->assertHasActionErrors(['start_time']);

        // Giờ kết thúc trước giờ bắt đầu: báo lỗi, không lưu.
        Livewire::test(WorkCalendar::class)
            ->callAction('createEntry', data: [...$data, 'start_time' => '12:00', 'end_time' => '09:00'])
            ->assertNotified('Giờ kết thúc phải sau giờ bắt đầu.');

        $this->assertSame(1, ScheduleEntry::query()->count());

        // Trùng lịch: form hiện cảnh báo trước khi lưu; vẫn lưu được và báo ai bị trùng.
        $this->assertSame(['Ngô Quay: trùng "Quay TVC" (08/10 · 08:00 – 12:00)'], ScheduleForm::conflictsFor($data));
        $this->assertSame([], ScheduleForm::conflictsFor([...$data, 'start_time' => '12:00', 'end_time' => '14:00']));
        $this->assertSame([], ScheduleForm::conflictsFor([...$data, 'status' => 'cancelled']));
        $this->assertSame([], ScheduleForm::conflictsFor([...$data, 'start_date' => 'đang gõ dở']));

        Livewire::test(WorkCalendar::class)
            ->callAction('createEntry', data: $data)
            ->assertHasNoActionErrors()
            ->assertNotified('Đã thêm lịch. Có người bị trùng lịch:');

        $this->assertSame(2, ScheduleEntry::query()->count());

        // Trên lịch tuần, thẻ bị trùng ghi rõ tên người.
        $this->get(WorkCalendar::getUrl(['date' => '2026-10-08']))->assertSee('Trùng lịch: Ngô Quay');
    }

    public function test_employee_sees_details_but_can_only_register_and_edit_own_leave(): void
    {
        $entry = $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['title' => 'Livestream', 'equipment' => '2 cam fix']);

        $view = fn (ScheduleEntry $entry): TestAction => TestAction::make('viewEntry')->arguments(['entry' => $entry->getKey()]);

        $this->actingAs($this->employee);

        Livewire::test(WorkCalendar::class)
            ->assertActionHidden('createEntry')
            ->assertActionHidden('publicLink')
            ->assertActionVisible('registerLeave')
            ->assertActionVisible('subscribe')
            // Mở chi tiết được, nhưng không có nút sửa / xoá / ghi giờ công.
            ->assertActionHidden([$view($entry), 'edit'])
            ->assertActionHidden([$view($entry), 'delete'])
            ->assertActionHidden([$view($entry), 'timesheets'])
            // Cố gọi thẳng thao tác sửa / xoá cũng bị từ chối.
            ->callAction('deleteEntry', arguments: ['entry' => $entry->getKey()])
            ->assertNotified('Bạn không có quyền xoá lịch này.')
            ->callAction('editEntry', data: ['title' => 'Đã sửa trộm'], arguments: ['entry' => $entry->getKey()])
            ->assertNotified('Bạn không có quyền xếp lịch.');

        $this->assertSame('Livestream', $entry->refresh()->title);

        // Đăng ký nghỉ hai ngày.
        Livewire::test(WorkCalendar::class)
            ->callAction('registerLeave', data: ['start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'notes' => 'Về quê'])
            ->assertHasNoActionErrors()
            ->assertNotified('Đã đăng ký nghỉ.');

        $leave = ScheduleEntry::query()->whereKeyNot($entry->getKey())->sole();

        $this->assertTrue($leave->isLeave());
        $this->assertSame('12/10 – 13/10 (cả ngày)', $leave->timeLabel());

        // Tự dời ngày nghỉ.
        Livewire::test(WorkCalendar::class)
            ->mountAction('editEntry', ['entry' => $leave->getKey()])
            ->setActionData(['start_date' => '2026-10-14', 'end_date' => '2026-10-14'])
            ->callMountedAction()
            ->assertNotified('Đã lưu lịch.');

        $this->assertSame('2026-10-14', $leave->refresh()->starts_at->toDateString());
        $this->assertSame([$this->employee->getKey()], $leave->crew->pluck('id')->all());

        // Với lịch nghỉ của chính mình thì có nút sửa và xoá.
        Livewire::test(WorkCalendar::class)
            ->assertActionVisible([$view($leave), 'edit'])
            ->assertActionVisible([$view($leave), 'delete']);

        $this->get(WorkCalendar::getUrl(['date' => '2026-10-14']))->assertSee('Nghỉ: Ngô Quay');
    }

    public function test_timesheets_are_logged_from_the_calendar(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Active]);
        $entry = $this->makeEntry('2026-10-06 08:00', '2026-10-06 12:00', [$this->employee], ['project_id' => $project->getKey(), 'title' => 'Quay TVC']);

        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->assertActionVisible([TestAction::make('viewEntry')->arguments(['entry' => $entry->getKey()]), 'timesheets'])
            ->mountAction('logTimesheets', ['entry' => $entry->getKey()])
            ->assertActionDataSet(['hours' => '4'])
            ->callMountedAction()
            ->assertNotified('Đã ghi 1 dòng giờ công.');

        $this->assertSame('600000.00', Timesheet::query()->sole()->cost);
        $this->assertSame('600000.00', $project->refresh()->labor_cost);
        $this->assertSame(ScheduleStatus::Done, $entry->refresh()->status);
    }

    public function test_public_link_is_off_by_default_and_is_controlled_by_planners(): void
    {
        $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['title' => 'Livestream hội thảo', 'location' => 'Studio A']);

        $this->assertFalse(PublicCalendarLink::isEnabled());
        $this->get('/lich/'.str_repeat('a', 40))->assertNotFound();

        // Người xếp lịch bật đường dẫn.
        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->assertActionVisible(['publicLink', 'enablePublicLink'])
            ->assertActionHidden(['publicLink', 'rotatePublicLink'])
            ->assertActionHidden(['publicLink', 'disablePublicLink'])
            ->callAction(['publicLink', 'enablePublicLink'])
            ->assertNotified('Đã bật đường dẫn chỉ-xem.');

        $token = PublicCalendarLink::token();

        $this->assertNotNull($token);
        $this->assertSame('Bật đường dẫn chỉ-xem của lịch', AuditLog::query()->where('action', 'settings_updated')->sole()->description);

        // Không cần đăng nhập: xem được lịch, không có nút thao tác, không bị lập chỉ mục.
        auth()->logout();

        $this->get(PublicCalendarLink::url())
            ->assertOk()
            ->assertSee('Livestream hội thảo')
            ->assertSee('Studio A')
            ->assertSee('chỉ xem')
            ->assertDontSee('wire:click', false)
            ->assertDontSee('Thêm lịch')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        // Chuyển tuần bằng tham số ?tuan=; giá trị lạ thì về tuần hiện tại.
        $this->get(PublicCalendarLink::url().'?tuan=2026-10-14')->assertOk()->assertSee('12/10 – 18/10/2026')->assertDontSee('Livestream hội thảo');
        $this->get(PublicCalendarLink::url().'?tuan=xyz')->assertOk()->assertSee('05/10 – 11/10/2026');

        // Mã sai → 404, không phân biệt với trang không tồn tại.
        $this->get('/lich/'.strrev($token))->assertNotFound();

        // Đổi mã: đường dẫn cũ chết ngay. Tắt: không còn đường dẫn nào.
        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->assertActionHidden(['publicLink', 'enablePublicLink'])
            ->callAction(['publicLink', 'rotatePublicLink'])
            ->assertNotified('Đã đổi đường dẫn chỉ-xem.');

        $new = PublicCalendarLink::token();
        auth()->logout();

        $this->assertNotSame($token, $new);
        $this->get('/lich/'.$token)->assertNotFound();
        $this->get('/lich/'.$new)->assertOk();

        $this->actingAs($this->pm);

        Livewire::test(WorkCalendar::class)
            ->callAction(['publicLink', 'disablePublicLink'])
            ->assertNotified('Đã tắt đường dẫn chỉ-xem.');

        auth()->logout();

        $this->get('/lich/'.$new)->assertNotFound();
        $this->assertNull(PublicCalendarLink::url());
    }

    public function test_calendar_feeds_export_schedules_in_icalendar_format(): void
    {
        $other = $this->createUserWithRole(SystemRole::Employee);

        $this->makeEntry('2026-10-08 08:00', '2026-10-08 11:30', [$this->employee], ['title' => 'Livestream; ra mắt, đợt 1', 'location' => 'Studio A']);
        $this->makeEntry('2026-10-12 00:00', '2026-10-13 00:00', [$this->employee], ['title' => 'Quay ngoại cảnh', 'is_all_day' => true, 'status' => ScheduleStatus::Tentative]);
        $this->makeEntry('2026-10-09 08:00', '2026-10-09 12:00', [$other], ['title' => 'Việc của người khác']);
        $this->makeEntry('2026-10-10 08:00', '2026-10-10 12:00', [$this->employee], ['title' => 'Việc đã huỷ', 'status' => ScheduleStatus::Cancelled]);

        $token = $this->employee->calendarToken();
        $response = $this->get(route('calendar.feed', ['token' => $token]))->assertOk();

        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));

        $ics = $response->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('X-WR-CALNAME:Lịch của Ngô Quay', $ics);

        // Giờ Việt Nam 08:00 – 11:30 = 01:00 – 04:30 UTC; ký tự đặc biệt được thoát.
        $this->assertStringContainsString('DTSTART:20261008T010000Z', $ics);
        $this->assertStringContainsString('DTEND:20261008T043000Z', $ics);
        $this->assertStringContainsString('SUMMARY:Livestream: Livestream\; ra mắt\, đợt 1', $ics);
        $this->assertStringContainsString('LOCATION:Studio A', $ics);

        // Lịch cả ngày 12–13/10: ngày kết thúc là ngày SAU ngày cuối.
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261012', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261014', $ics);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);

        // Chỉ lịch của mình, không có lịch đã huỷ.
        $this->assertStringNotContainsString('Việc của người khác', $ics);
        $this->assertStringNotContainsString('Việc đã huỷ', $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));

        // Không dòng nào dài quá 75 byte.
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }

        // Đổi mã thì đường dẫn cũ hết hiệu lực; tài khoản bị khoá thì nguồn lịch cũng đóng.
        $newToken = $this->employee->rotateCalendarToken();

        $this->get(route('calendar.feed', ['token' => $token]))->assertNotFound();
        $this->get(route('calendar.feed', ['token' => $newToken]))->assertOk();

        $this->employee->update(['is_active' => false]);

        $this->get(route('calendar.feed', ['token' => $newToken]))->assertNotFound();

        // Nguồn lịch chung chỉ mở khi đường dẫn chỉ-xem đang bật.
        $this->get('/lich/'.str_repeat('b', 40).'/lich.ics')->assertNotFound();

        PublicCalendarLink::rotate();

        $this->assertSame(3, substr_count($this->get(PublicCalendarLink::feedUrl())->assertOk()->getContent(), 'BEGIN:VEVENT'));
    }

    public function test_schedule_types_are_managed_by_planners_and_protected_when_in_use(): void
    {
        $used = ScheduleType::query()->where('name', 'Livestream')->first();
        $unused = ScheduleType::factory()->create(['name' => 'Tạm thời']);
        $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', attributes: ['schedule_type_id' => $used->getKey()]);

        $this->actingAs($this->employee)->get(ScheduleTypeResource::getUrl('index'))->assertForbidden();

        $this->actingAs($this->pm)->get(ScheduleTypeResource::getUrl('index'))->assertOk()->assertSee('Livestream');

        $this->assertTrue($this->pm->cannot('delete', $used));
        $this->assertTrue($this->pm->can('delete', $unused));

        Livewire::test(ManageScheduleTypes::class)
            ->callAction('create', data: ['name' => 'Flycam', 'color' => 'forest', 'sort_order' => 35])
            ->assertHasNoActionErrors();

        $this->assertSame('#008300', ScheduleType::query()->where('name', 'Flycam')->sole()->hex());

        // Loại đã ngừng dùng không còn trong danh sách chọn, lịch cũ vẫn hiện bình thường.
        $used->update(['is_active' => false]);

        $this->assertNotContains('Livestream', ScheduleForm::typeOptions());
        $this->get(WorkCalendar::getUrl(['date' => '2026-10-08']))->assertOk();
    }

    public function test_project_page_lists_its_schedules_and_dashboard_shows_my_week(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Active, 'project_manager_id' => $this->pm->getKey()]);
        $mine = $this->makeEntry('2026-10-08 08:00', '2026-10-08 12:00', [$this->employee], ['project_id' => $project->getKey(), 'title' => 'Quay TVC ngày 1']);
        $other = $this->makeEntry('2026-10-09 08:00', '2026-10-09 12:00', [$this->pm], ['title' => 'Việc dự án khác']);
        $far = $this->makeEntry('2026-10-20 08:00', '2026-10-20 12:00', [$this->employee], ['title' => 'Việc còn xa']);

        $this->actingAs($this->pm);

        Livewire::test(ScheduleRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other, $far]);

        // Dashboard: mỗi người thấy lịch 7 ngày tới của chính mình.
        Livewire::actingAs($this->employee)
            ->test(MySchedule::class)
            ->assertSee('Quay TVC ngày 1')
            ->assertDontSee('Việc dự án khác')
            ->assertDontSee('Việc còn xa');

        $this->actingAs($this->createUserWithRole(SystemRole::Accountant))
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Bạn chưa có lịch nào trong 7 ngày tới');

        // Số trên menu: lịch hôm nay của tôi.
        $this->makeEntry('2026-10-07 13:00', '2026-10-07 15:00', [$this->employee]);
        $this->actingAs($this->employee);

        $this->assertSame('1', WorkCalendar::getNavigationBadge());
    }
}
