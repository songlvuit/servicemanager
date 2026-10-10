<?php

namespace Database\Seeders;

use App\Enums\ScheduleStatus;
use App\Enums\SystemRole;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Scheduling\ScheduleManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu của Lịch làm việc: 4 tài khoản ê-kíp và khoảng 20 lịch quanh
 * tuần này — có lịch nhiều ngày, lịch dự kiến, lịch đã huỷ, một ngày nghỉ và
 * một trường hợp trùng lịch để thấy cảnh báo.
 *
 * Chỉ chạy một lần: bỏ qua nếu đã có lịch, và không chạy trên production.
 */
class DemoScheduleSeeder extends Seeder
{
    /**
     * @var list<array{email: string, name: string, position: string, rate: int}>
     */
    private const CREW = [
        ['email' => 'quay@example.com', 'name' => 'Ngô Văn Quay', 'position' => 'Quay phim', 'rate' => 600000],
        ['email' => 'dung@example.com', 'name' => 'Bùi Thị Dựng', 'position' => 'Dựng phim', 'rate' => 560000],
        ['email' => 'sang@example.com', 'name' => 'Đặng Văn Sáng', 'position' => 'Kỹ thuật ánh sáng', 'rate' => 480000],
        ['email' => 'thanh@example.com', 'name' => 'Vũ Minh Thanh', 'position' => 'Kỹ thuật âm thanh', 'rate' => 480000],
    ];

    public function run(ScheduleManager $schedule): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoScheduleSeeder: không tạo dữ liệu mẫu trên production.');

            return;
        }

        // Công việc mẫu gắn hợp đồng tự có lịch, nên chỉ xét lịch do chính seeder này tạo:
        // lịch nghỉ và lịch nhập thẳng (công việc của nó mang cờ from_schedule).
        $seeded = ScheduleEntry::query()
            ->where(fn ($query) => $query
                ->whereNull('task_id')
                ->orWhereHas('task', fn ($query) => $query->where('from_schedule', true)))
            ->exists();

        if ($seeded) {
            $this->command?->info('Bỏ qua DemoScheduleSeeder: đã có dữ liệu lịch.');

            return;
        }

        $pm = User::query()->where('email', 'pm@example.com')->first();
        $employee = User::query()->where('email', 'employee@example.com')->first();
        $types = ScheduleType::query()->pluck('id', 'name');

        if ($pm === null || $employee === null || $types->isEmpty()) {
            $this->command?->warn('Bỏ qua DemoScheduleSeeder: chưa có tài khoản mẫu hoặc loại lịch (chạy migration và DevUserSeeder trước).');

            return;
        }

        $people = ['pm' => $pm, 'vien' => $employee, ...$this->seedCrew()];
        $monday = CarbonImmutable::today()->startOfWeek();

        /*
         * [ngày tính từ thứ Hai tuần này, giờ bắt đầu, giờ kết thúc (null = cả ngày), số ngày,
         *  loại, tên, ê-kíp, địa điểm, thiết bị, trạng thái, CTV ngoài]
         */
        $rows = [
            // Tuần trước: đã xong.
            [-5, '08:00', '17:00', 1, 'Quay phim', 'Quay bài giảng 5–6', ['quay', 'sang', 'thanh'], 'Studio A', '2 cam fix, 3 đèn, 2 mic cài áo', ScheduleStatus::Done, null],
            [-3, '13:00', '17:30', 1, 'Hậu kỳ', 'Dựng bài giảng 3–4', ['dung'], 'Văn phòng', null, ScheduleStatus::Done, null],

            // Tuần này.
            [0, '08:30', '10:00', 1, 'Họp / khảo sát', 'Họp kickoff TVC với khách', ['pm', 'quay'], 'Văn phòng khách hàng, Quận 1', null, ScheduleStatus::Confirmed, null],
            [0, '13:00', '18:00', 1, 'Quay phim', 'Quay bài giảng 7–8', ['quay', 'sang', 'thanh'], 'Studio A', '2 cam fix, 3 đèn, phông xanh', ScheduleStatus::Confirmed, null],
            [1, '07:00', '12:00', 1, 'Livestream', 'Livestream hội thảo trực tuyến', ['thanh', 'vien'], 'Khách sạn Riverside', '2 cam fix, 1 gimbal, bộ trộn hình, intercom', ScheduleStatus::Confirmed, '1 kỹ thuật thuê ngoài'],
            [1, '14:00', '17:00', 1, 'Hậu kỳ', 'Dựng bài giảng 5–6', ['dung'], 'Văn phòng', null, ScheduleStatus::Confirmed, null],
            [2, '06:00', '18:00', 2, 'Quay phim', 'Quay TVC ngoại cảnh Đà Lạt', ['pm', 'quay', 'sang'], 'Đà Lạt', '1 cam chính, 1 gimbal, flycam, 4 đèn', ScheduleStatus::Confirmed, '1 trợ lý thuê tại chỗ'],
            // Trùng lịch có chủ ý: Sáng đang ở Đà Lạt nhưng vẫn bị xếp vào buổi chụp.
            [3, '09:00', '12:00', 1, 'Chụp ảnh', 'Chụp ảnh sản phẩm bổ sung', ['sang', 'vien'], 'Studio B', '2 đèn flash, softbox, bàn xoay', ScheduleStatus::Confirmed, null],
            [3, '13:30', '17:00', 1, 'Livestream', 'Livestream ra mắt khoá học', ['thanh', 'vien'], 'Studio A', '3 cam, bộ trộn hình, 2 mic', ScheduleStatus::Confirmed, null],
            [4, '08:00', '12:00', 1, 'Hậu kỳ', 'Dựng bản nháp TVC', ['dung'], 'Văn phòng', null, ScheduleStatus::Confirmed, null],
            [4, '13:00', '21:00', 1, 'Màn hình LED', 'Show ca nhạc — vận hành màn hình LED', ['quay', 'sang', 'thanh'], 'Nhà văn hoá Thanh Niên', '1 DOP, 2 cam, intercom', ScheduleStatus::Tentative, '2 nhân sự kỹ thuật LED'],
            [5, '07:00', '12:00', 1, 'Livestream', 'Livestream talkshow bất động sản', ['quay', 'thanh', 'vien'], 'Nhà mẫu dự án', '3 cam fix, 1 gimbal', ScheduleStatus::Confirmed, null],
            [5, '14:00', '16:00', 1, 'Họp / khảo sát', 'Khảo sát địa điểm sự kiện', ['pm'], 'Trung tâm hội nghị', null, ScheduleStatus::Cancelled, null],
            [6, '10:00', '17:00', 1, 'Quay phim', 'Quay phóng sự doanh nghiệp', ['quay', 'sang'], 'Vĩnh Long', '2 cam fix, 1 gimbal', ScheduleStatus::Confirmed, null],

            // Tuần sau.
            [7, '08:00', '17:00', 1, 'Quay phim', 'Quay bài giảng 9–10', ['quay', 'sang', 'thanh'], 'Studio A', '2 cam fix, 3 đèn', ScheduleStatus::Confirmed, null],
            [8, '09:00', '11:00', 1, 'Họp / khảo sát', 'Duyệt bản dựng TVC với khách', ['pm', 'dung'], 'Trực tuyến', null, ScheduleStatus::Tentative, null],
            [9, null, null, 3, 'Quay phim', 'Quay ngoại cảnh khoá học đợt 2', ['quay', 'sang', 'vien'], 'Hội An', '2 cam, flycam, 4 đèn', ScheduleStatus::Tentative, null],
            [10, '13:00', '17:00', 1, 'Hậu kỳ', 'Dựng bài giảng 7–8', ['dung'], 'Văn phòng', null, ScheduleStatus::Confirmed, null],
            [12, '08:00', '12:00', 1, 'Livestream', 'Livestream hội nghị khách hàng', ['thanh', 'vien', 'quay'], 'Khách sạn Riverside', '3 cam, bộ trộn hình', ScheduleStatus::Confirmed, null],
        ];

        foreach ($rows as [$offset, $from, $to, $days, $type, $title, $crew, $location, $equipment, $status, $external]) {
            if (! $types->has($type)) {
                continue;
            }

            $day = $monday->addDays($offset);
            $allDay = $from === null;

            $schedule->save(null, [
                'title' => $title,
                'schedule_type_id' => $types[$type],
                'starts_at' => $allDay ? $day->startOfDay() : $day->setTimeFromTimeString($from),
                'ends_at' => $allDay ? $day->addDays($days - 1)->endOfDay() : $day->addDays($days - 1)->setTimeFromTimeString($to),
                'is_all_day' => $allDay,
                'location' => $location,
                'equipment' => $equipment,
                'external_crew' => $external,
                'status' => $status,
            ], array_map(fn (string $key): int => $people[$key]->getKey(), $crew), $pm);
        }

        // Một người tự đăng ký nghỉ thứ Bảy tuần này.
        $schedule->registerLeave(
            $people['dung'],
            $monday->addDays(5)->startOfDay(),
            $monday->addDays(5)->endOfDay(),
            note: 'Việc gia đình',
        );
    }

    /**
     * @return array<string, User>
     */
    private function seedCrew(): array
    {
        $crew = [];

        foreach (self::CREW as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'position' => $account['position'],
                    'password' => DevUserSeeder::PASSWORD,
                    'session_rate' => $account['rate'],
                    'is_active' => true,
                ],
            );

            if ($user->wasRecentlyCreated) {
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->syncRoles([SystemRole::Employee->value]);
            }

            $crew[strstr($account['email'], '@', true)] = $user;
        }

        return $crew;
    }
}
