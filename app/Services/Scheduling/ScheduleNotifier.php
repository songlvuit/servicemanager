<?php

namespace App\Services\Scheduling;

use App\Filament\Pages\WorkCalendar;
use App\Models\ScheduleEntry;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Thông báo trong ứng dụng về lịch làm việc: được xếp lịch, lịch đổi giờ /
 * địa điểm, lịch bị huỷ, bị rút khỏi lịch, nhắc lịch ngày mai, có người
 * đăng ký nghỉ.
 */
class ScheduleNotifier
{
    /**
     * @param  Collection<int, User>  $users
     */
    public function assigned(ScheduleEntry $entry, Collection $users): void
    {
        $this->send($users, $entry, 'Bạn có lịch mới', $this->summary($entry), 'info');
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function changed(ScheduleEntry $entry, Collection $users, string $what): void
    {
        $this->send($users, $entry, 'Lịch của bạn đã thay đổi', "{$what}. Hiện tại: ".$this->summary($entry), 'warning');
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function cancelled(ScheduleEntry $entry, Collection $users): void
    {
        $this->send($users, $entry, 'Lịch đã bị huỷ', $this->summary($entry), 'danger');
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function removed(ScheduleEntry $entry, Collection $users): void
    {
        $this->send($users, $entry, 'Bạn không còn trong lịch', $this->summary($entry), 'warning');
    }

    public function reminder(ScheduleEntry $entry, User $user): void
    {
        $this->send(collect([$user]), $entry, 'Nhắc lịch ngày mai', $this->summary($entry), 'info');
    }

    /**
     * Có người tự đăng ký nghỉ: báo cho những người xếp lịch.
     */
    public function leaveRegistered(ScheduleEntry $entry, User $by): void
    {
        try {
            $planners = User::query()
                ->where('is_active', true)
                ->permission('schedules.create')
                ->whereKeyNot($by->getKey())
                ->get();
        } catch (Throwable) {
            // Chưa có danh mục quyền (chưa chạy seeder): không có ai để báo.
            return;
        }

        $this->send($planners, $entry, 'Đăng ký nghỉ', "{$by->name}: ".$this->when($entry).(filled($entry->notes) ? " — {$entry->notes}" : ''), 'info');
    }

    /**
     * "Livestream Google — 08/10 08:00 – 11:30 tại Studio A".
     */
    public function summary(ScheduleEntry $entry): string
    {
        return $entry->title.' — '.$this->when($entry).(filled($entry->location) ? " tại {$entry->location}" : '');
    }

    private function when(ScheduleEntry $entry): string
    {
        return $entry->isMultiDay()
            ? $entry->timeLabel()
            : $entry->starts_at->format('d/m/Y').($entry->is_all_day ? ' (cả ngày)' : ' '.$entry->timeLabel());
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, ScheduleEntry $entry, string $title, string $body, string $status): void
    {
        $recipients = $users->filter(fn (User $user): bool => (bool) $user->is_active)->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-calendar-days')
            ->status($status)
            ->actions([
                Action::make('view')
                    ->label('Xem lịch')
                    ->url(WorkCalendar::entryUrl($entry)),
            ])
            ->sendToDatabase($recipients);
    }
}
