<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\Pages\Notifications;
use App\Models\User;
use App\Services\Notifications\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsInvoices;
use Tests\Concerns\BuildsJobs;
use Tests\TestCase;

/**
 * Thông báo nhắc việc hằng ngày và màn hình Thông báo (mục 38).
 */
class ReminderTest extends TestCase
{
    use BuildsInvoices;
    use BuildsJobs;
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
        $this->travelTo(Carbon::parse('2026-10-15 07:30:00'));

        $this->director = $this->createUserWithRole(SystemRole::Director);
    }

    public function test_each_event_is_reminded_exactly_once(): void
    {
        $author = $this->createUserWithPermissions(['contracts.view', 'contracts.create']);

        // Hợp đồng hết hạn sau 10 ngày (trong ngưỡng 30 ngày) và một hợp đồng còn xa.
        $expiring = $this->makeActiveContract(['end_date' => today()->addDays(10), 'created_by' => $author->getKey()]);
        $this->makeActiveContract(['end_date' => today()->addDays(90)]);

        // Hóa đơn tới hạn sau 3 ngày, hóa đơn quá hạn 5 ngày, hóa đơn còn xa hạn.
        $dueSoon = $this->issuedInvoice(['invoice_date' => today()->subDays(27), 'due_date' => today()->addDays(3)]);
        $overdue = $this->issuedInvoice(['invoice_date' => today()->subDays(35), 'due_date' => today()->subDays(5)]);
        $this->issuedInvoice(['due_date' => today()->addDays(25)]);

        $this->assertSame(
            ['contracts' => 1, 'invoices_due' => 1, 'invoices_overdue' => 1, 'schedules' => 0],
            app(ReminderService::class)->send(),
        );

        $titles = fn (User $user): array => $user->notifications()->get()->map(fn ($n) => $n->data['title'])->sort()->values()->all();

        // Giám đốc: hợp đồng, hóa đơn quá hạn. Kế toán: hai hóa đơn. Người lập hợp đồng: hợp đồng của mình.
        $this->assertSame(['Hóa đơn quá hạn thanh toán', 'Hợp đồng sắp hết hạn'], $titles($this->director));
        $this->assertSame(['Hóa đơn quá hạn thanh toán', 'Hóa đơn sắp tới hạn thanh toán'], $titles($this->accountant()));
        $this->assertSame(['Hợp đồng sắp hết hạn'], $titles($author));

        $contractNotice = $author->notifications()->first();

        $this->assertStringContainsString($expiring->code, $contractNotice->data['body']);
        $this->assertStringContainsString('còn 10 ngày', $contractNotice->data['body']);
        $this->assertStringContainsString("/admin/contracts/{$expiring->getKey()}", $contractNotice->data['actions'][0]['url']);

        $body = $this->accountant()->notifications()->get()->firstWhere(fn ($n) => $n->data['title'] === 'Hóa đơn quá hạn thanh toán')->data['body'];

        $this->assertStringContainsString($overdue->code, $body);
        $this->assertStringContainsString('11.000.000 ₫', $body);
        $this->assertStringContainsString('đã quá hạn 5 ngày', $body);

        // Chạy lại (hôm sau cũng vậy): không nhắc trùng.
        $this->travel(1)->days();

        $this->assertSame(
            ['contracts' => 0, 'invoices_due' => 0, 'invoices_overdue' => 0, 'schedules' => 0],
            app(ReminderService::class)->send(),
        );

        // Hóa đơn sắp tới hạn nay đã quá hạn → được nhắc ở mức "quá hạn".
        $this->travel(5)->days();

        $this->assertSame(1, app(ReminderService::class)->invoicesOverdue());
        $this->assertStringContainsString($dueSoon->code, $this->accountant()->notifications()->first()->data['body']);

        // Đã thu đủ thì không còn nhắc.
        $late = $this->issuedInvoice(['invoice_date' => today()->subDays(40), 'due_date' => today()->subDays(10)]);
        $this->pay($late, 11000000);

        $this->assertSame(0, app(ReminderService::class)->invoicesOverdue());
    }

    /**
     * Người lập hợp đồng / hóa đơn nay không còn quyền xem thì không được nhắc
     * (thông báo có mã, tên khách hàng và số tiền).
     */
    public function test_creators_who_lost_access_are_not_reminded(): void
    {
        $sales = $this->createUserWithRole(SystemRole::Sales);

        $this->makeActiveContract(['end_date' => today()->addDays(10), 'created_by' => $sales->getKey()]);
        $this->issuedInvoice(['invoice_date' => today()->subDays(35), 'due_date' => today()->subDays(5), 'created_by' => $sales->getKey()]);

        app(ReminderService::class)->send();

        $this->assertSame(0, $sales->notifications()->count());
        $this->assertSame(2, $this->director->notifications()->count());
    }

    public function test_reminder_command_reports_what_it_sent(): void
    {
        $this->issuedInvoice(['invoice_date' => today()->subDays(35), 'due_date' => today()->subDays(5)]);

        $this->artisan('reminders:send')
            ->expectsOutputToContain('1 hóa đơn quá hạn')
            ->assertSuccessful();

        $this->artisan('reminders:send')
            ->expectsOutputToContain('0 hóa đơn quá hạn')
            ->assertSuccessful();
    }

    public function test_notification_page_lists_only_the_users_own_notifications(): void
    {
        $this->issuedInvoice(['invoice_date' => today()->subDays(35), 'due_date' => today()->subDays(5)]);
        $this->makeActiveContract(['end_date' => today()->addDays(10)]);

        app(ReminderService::class)->send();

        $mine = Notifications::class;
        $this->actingAs($this->director);

        $own = DatabaseNotification::query()->where('notifiable_id', $this->director->getKey())->get();
        $others = DatabaseNotification::query()->where('notifiable_id', $this->accountant()->getKey())->get();

        $this->assertCount(2, $own);
        $this->assertSame('2', Notifications::getNavigationBadge());

        $this->get(Notifications::getUrl())->assertOk()->assertSee('Hợp đồng sắp hết hạn');

        Livewire::test($mine)
            ->assertCanSeeTableRecords($own)
            ->assertCanNotSeeTableRecords($others)
            ->callTableAction('markAsRead', $own->first())
            ->assertSuccessful();

        $this->assertNotNull($own->first()->refresh()->read_at);
        $this->assertSame('1', Notifications::getNavigationBadge());

        Livewire::test($mine)
            ->filterTable('read_at', true)
            ->assertCanSeeTableRecords([$own->last()])
            ->assertCanNotSeeTableRecords([$own->first()])
            ->callAction('markAllAsRead');

        $this->assertNull(Notifications::getNavigationBadge());
        // Thông báo của người khác không bị đánh dấu theo.
        $this->assertTrue($others->every(fn (DatabaseNotification $n): bool => $n->refresh()->read_at === null));
    }

    public function test_opening_a_notification_marks_it_read_and_only_follows_internal_links(): void
    {
        $invoice = $this->issuedInvoice(['invoice_date' => today()->subDays(35), 'due_date' => today()->subDays(5)]);

        app(ReminderService::class)->send();

        $this->actingAs($this->director);

        $notification = DatabaseNotification::query()->where('notifiable_id', $this->director->getKey())->sole();

        Livewire::test(Notifications::class)
            ->callTableAction('open', $notification)
            ->assertRedirect($notification->data['actions'][0]['url']);

        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertStringContainsString("/admin/invoices/{$invoice->getKey()}", Notifications::targetUrl($notification));

        // Đường dẫn ra ngoài hệ thống không được dùng làm nút "Mở".
        $external = new DatabaseNotification(['data' => ['actions' => [['url' => 'https://evil.example/phish']]]]);
        $protocolRelative = new DatabaseNotification(['data' => ['actions' => [['url' => '//evil.example']]]]);
        $relative = new DatabaseNotification(['data' => ['actions' => [['url' => '/admin/tasks/1']]]]);

        $this->assertNull(Notifications::targetUrl($external));
        $this->assertNull(Notifications::targetUrl($protocolRelative));
        $this->assertSame('/admin/tasks/1', Notifications::targetUrl($relative));
    }

    public function test_error_pages_are_in_vietnamese_without_technical_details(): void
    {
        $this->get('/trang-khong-ton-tai')
            ->assertNotFound()
            ->assertSee('Không tìm thấy trang')
            ->assertSee('Về trang chủ');

        $this->assertStringContainsString(
            'Đã xảy ra lỗi. Vui lòng thử lại hoặc liên hệ quản trị viên.',
            view('errors.500')->render(),
        );
    }
}
