<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\WorkCalendar;
use App\Services\Scheduling\CalendarQuery;
use Filament\Widgets\Widget;

/**
 * "Lịch của tôi" trên dashboard: các lịch của người đang đăng nhập trong 7
 * ngày tới, bấm vào để mở trên trang Lịch làm việc.
 */
class MySchedule extends Widget
{
    private const DAYS = 7;

    protected static ?int $sort = 15;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.my-schedule';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'agenda' => $user ? app(CalendarQuery::class)->agenda($user, today(), self::DAYS) : [],
            'days' => self::DAYS,
            'calendarUrl' => WorkCalendar::getUrl(),
            'mineUrl' => WorkCalendar::getUrl(['view' => 'mine']),
        ];
    }
}
