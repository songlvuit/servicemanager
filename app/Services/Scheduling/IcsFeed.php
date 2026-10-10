<?php

namespace App\Services\Scheduling;

use App\Enums\ScheduleStatus;
use App\Models\ScheduleEntry;
use Illuminate\Support\Collection;

/**
 * Xuất lịch ra định dạng iCalendar (RFC 5545) để đăng ký vào Google Calendar,
 * lịch của iPhone / Android, Outlook… Ứng dụng lịch tự tải lại định kỳ nên
 * lịch trên điện thoại luôn theo kịp hệ thống.
 */
class IcsFeed
{
    /**
     * @param  Collection<int, ScheduleEntry>  $entries  Đã nạp type, crew, customer.
     */
    public function render(Collection $entries, string $calendarName): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'service-manager';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Service Manager//Lich lam viec//VI',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:'.config('app.timezone'),
            // Gợi ý ứng dụng lịch tải lại mỗi giờ.
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($entries as $entry) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:schedule-{$entry->getKey()}@{$host}";
            $lines[] = 'DTSTAMP:'.($entry->updated_at ?? now())->copy()->utc()->format('Ymd\THis\Z');

            if ($entry->is_all_day) {
                // Sự kiện cả ngày: DTEND là ngày SAU ngày cuối cùng.
                $lines[] = 'DTSTART;VALUE=DATE:'.$entry->starts_at->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$entry->ends_at->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$entry->starts_at->copy()->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$entry->ends_at->copy()->utc()->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:'.$this->escape(($entry->type ? $entry->type->name.': ' : '').$entry->title);

            if (filled($entry->location)) {
                $lines[] = 'LOCATION:'.$this->escape((string) $entry->location);
            }

            if ($description = $this->description($entry)) {
                $lines[] = 'DESCRIPTION:'.$this->escape($description);
            }

            $lines[] = 'STATUS:'.match ($entry->status) {
                ScheduleStatus::Tentative => 'TENTATIVE',
                ScheduleStatus::Cancelled => 'CANCELLED',
                default => 'CONFIRMED',
            };
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function description(ScheduleEntry $entry): string
    {
        return implode("\n", array_filter([
            filled($entry->crewLabel()) ? 'Ê-kíp: '.$entry->crewLabel() : null,
            $entry->customer ? 'Khách hàng: '.$entry->customer->name : null,
            filled($entry->equipment) ? 'Thiết bị: '.$entry->equipment : null,
            filled($entry->notes) ? 'Ghi chú: '.$entry->notes : null,
        ]));
    }

    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', ''],
            $text,
        );
    }

    /**
     * Dòng dài hơn 75 byte phải ngắt, dòng nối bắt đầu bằng một dấu cách —
     * và không được cắt giữa một ký tự UTF-8.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = [];
        $current = '';

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > ($folded === [] ? 75 : 74)) {
                $folded[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        $folded[] = $current;

        return implode("\r\n ", $folded);
    }
}
