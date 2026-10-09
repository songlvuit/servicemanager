<?php

namespace App\Http\Controllers;

use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Scheduling\CalendarQuery;
use App\Services\Scheduling\IcsFeed;
use App\Support\PublicCalendarLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Các trang lịch mở được mà KHÔNG cần đăng nhập, bảo vệ bằng mã bí mật trong
 * đường dẫn:
 *
 *   /lich/{mã}            lịch tuần chỉ-xem của cả công ty
 *   /lich/{mã}/lich.ics   nguồn iCalendar của cả công ty
 *   /lich-cua-toi/{mã}    nguồn iCalendar của riêng một người
 *
 * Mã sai hoặc đường dẫn đã tắt đều trả về 404 như một trang không tồn tại.
 * Các trang này chỉ đọc dữ liệu, không có thao tác ghi nào.
 */
class PublicCalendarController extends Controller
{
    /** Nguồn iCalendar gồm lịch từ 30 ngày trước tới 180 ngày sau. */
    private const FEED_PAST_DAYS = 30;

    private const FEED_FUTURE_DAYS = 180;

    public function show(Request $request, string $token, CalendarQuery $calendar): Response
    {
        abort_unless(PublicCalendarLink::matches($token), 404);

        $date = $this->date($request->query('tuan'));
        $week = $calendar->week($date);

        return $this->private(response()->view('calendar.public', [
            'week' => $week,
            'types' => ScheduleType::query()->active()->ordered()->get(),
            'previousUrl' => route('calendar.public', ['token' => $token, 'tuan' => $week['from']->subWeek()->toDateString()]),
            'nextUrl' => route('calendar.public', ['token' => $token, 'tuan' => $week['from']->addWeek()->toDateString()]),
            'todayUrl' => route('calendar.public', ['token' => $token]),
            'isCurrentWeek' => $week['from']->isSameDay(CarbonImmutable::today()->startOfWeek()),
        ]));
    }

    public function feed(string $token, CalendarQuery $calendar, IcsFeed $ics): Response
    {
        abort_unless(PublicCalendarLink::matches($token), 404);

        $entries = $calendar->entries(...$this->feedRange());

        return $this->calendar($ics->render($entries, 'Lịch làm việc — '.config('company.name', config('app.name'))));
    }

    public function personal(string $token, CalendarQuery $calendar, IcsFeed $ics): Response
    {
        $user = User::query()->where('calendar_token', $token)->where('is_active', true)->first();

        abort_if($user === null, 404);

        $entries = $calendar->entries(...[...$this->feedRange(), ['user' => $user->getKey()]]);

        return $this->calendar($ics->render($entries, 'Lịch của '.$user->name));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function feedRange(): array
    {
        return [
            CarbonImmutable::today()->subDays(self::FEED_PAST_DAYS),
            CarbonImmutable::today()->addDays(self::FEED_FUTURE_DAYS)->endOfDay(),
        ];
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                // Ngày không hợp lệ: về tuần hiện tại.
            }
        }

        return CarbonImmutable::today();
    }

    private function calendar(string $body): Response
    {
        return $this->private(response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="lich.ics"',
        ]));
    }

    /**
     * Không cho công cụ tìm kiếm lập chỉ mục, không lưu ở bộ đệm dùng chung,
     * không để lộ đường dẫn (có mã) qua Referer khi bấm sang trang khác.
     */
    private function private(Response $response): Response
    {
        return $response->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
