<?php

namespace App\Filament\Support\Scheduling;

use App\Enums\ScheduleStatus;
use App\Filament\Support\Access;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Scheduling\ScheduleConflicts;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

/**
 * Form của một lịch. Trên giao diện nay chỉ còn dùng để sửa LỊCH NGHỈ (lịch
 * công việc đi theo công việc — xem TaskForm, TaskSchedule); các hàm ghép /
 * tách ngày giờ và kiểm tra trùng lịch vẫn dùng chung cho form công việc.
 *
 * Form nhập ngày và giờ riêng (start_date, end_date, start_time, end_time);
 * toAttributes() ghép lại thành starts_at / ends_at để lưu, fromEntry() tách
 * ra khi mở lịch để sửa.
 */
class ScheduleForm
{
    public const DEFAULT_START = '08:00';

    public const DEFAULT_END = '12:00';

    /**
     * @param  bool  $restricted  Người chỉ sửa lịch nghỉ của chính mình: chỉ đổi được thời gian và ghi chú.
     * @return list<Component>
     */
    public static function components(?int $entryId = null, bool $restricted = false, bool $withProject = true): array
    {
        return [Grid::make(2)->schema(static::fields($entryId, $restricted, $withProject))];
    }

    /**
     * @return list<Component>
     */
    private static function fields(?int $entryId, bool $restricted, bool $withProject): array
    {
        return [
            TextInput::make('title')
                ->label('Tên lịch')
                ->placeholder('Ví dụ: Livestream ra mắt sản phẩm')
                ->required()
                ->maxLength(255)
                ->disabled($restricted)
                ->columnSpanFull(),
            Select::make('schedule_type_id')
                ->label('Loại lịch')
                ->options(fn (): array => static::typeOptions())
                ->required()
                ->live()
                ->disabled($restricted),
            Select::make('status')
                ->label('Trạng thái')
                ->options(ScheduleStatus::class)
                ->default(ScheduleStatus::Confirmed->value)
                ->required()
                ->disabled($restricted),
            DatePicker::make('start_date')
                ->label('Ngày bắt đầu')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(today()->toDateString())
                ->required()
                ->live()
                // Đổi ngày bắt đầu thì ngày kết thúc đi theo nếu đang sớm hơn.
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    if (filled($state) && (blank($get('end_date')) || (string) $get('end_date') < (string) $state)) {
                        $set('end_date', $state);
                    }
                }),
            DatePicker::make('end_date')
                ->label('Ngày kết thúc')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(today()->toDateString())
                ->required()
                ->afterOrEqual('start_date')
                ->live(),
            static::timeInput('start_time', 'Từ giờ', static::DEFAULT_START),
            static::timeInput('end_time', 'Đến giờ', static::DEFAULT_END),
            Toggle::make('is_all_day')
                ->label('Cả ngày')
                ->helperText('Bật khi lịch kéo dài trọn ngày (hoặc nhiều ngày liền).')
                ->live()
                ->columnSpanFull(),
            Select::make('crew')
                ->label('Ê-kíp')
                ->options(fn (): array => static::userOptions())
                ->multiple()
                ->placeholder('Chọn người tham gia')
                ->searchable()
                ->live()
                ->disabled($restricted)
                ->helperText('Người được chọn sẽ nhận thông báo và thấy lịch này trong "Lịch của tôi".')
                ->columnSpanFull(),
            Callout::make('Trùng lịch')
                ->description(fn (Get $get): string => implode("\n", static::conflictLines($get, $entryId)))
                ->warning()
                ->visible(fn (Get $get): bool => static::conflictLines($get, $entryId) !== [])
                ->columnSpanFull(),
            TextInput::make('external_crew')
                ->label('Cộng tác viên ngoài')
                ->placeholder('Ví dụ: 2 quay phim thuê ngoài, 1 trợ lý')
                ->maxLength(255)
                ->disabled($restricted)
                ->hidden(fn (Get $get): bool => static::isLeaveType($get('schedule_type_id')))
                ->columnSpanFull(),
            Select::make('project_id')
                ->label('Dự án')
                ->options(fn (?ScheduleEntry $record, Get $get): array => static::projectOptions($get('project_id')))
                ->searchable()
                ->placeholder('Không gắn dự án')
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if (filled($state)) {
                        $set('customer_id', Project::query()->withTrashed()->find($state)?->customer_id);
                    }
                })
                ->helperText('Bỏ trống nếu là việc độc lập. Gắn dự án thì giờ công và chi phí của lịch tính vào dự án đó.')
                ->visible($withProject)
                ->disabled($restricted)
                ->hidden(fn (Get $get): bool => static::isLeaveType($get('schedule_type_id'))),
            Select::make('customer_id')
                ->label('Khách hàng')
                ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder('Chưa chọn')
                // Đã gắn dự án thì khách hàng là khách hàng của dự án.
                ->disabled(fn (Get $get): bool => $restricted || filled($get('project_id')))
                ->dehydrated()
                ->visible($withProject)
                ->hidden(fn (Get $get): bool => static::isLeaveType($get('schedule_type_id'))),
            TextInput::make('location')
                ->label('Địa điểm')
                ->maxLength(255)
                ->disabled($restricted)
                ->hidden(fn (Get $get): bool => static::isLeaveType($get('schedule_type_id')))
                ->columnSpanFull(),
            Textarea::make('equipment')
                ->label('Thiết bị')
                ->placeholder('Ví dụ: 2 cam fix, 1 gimbal, 3 mic, phông xanh')
                ->rows(2)
                ->maxLength(2000)
                ->disabled($restricted)
                ->hidden(fn (Get $get): bool => static::isLeaveType($get('schedule_type_id')))
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Ghi chú')
                ->rows(2)
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    /**
     * Ô nhập giờ dạng 24 giờ "HH:MM" (gõ số, có gợi ý các mốc 30 phút). Không
     * dùng ô giờ của trình duyệt vì ô đó hiện AM/PM theo ngôn ngữ máy.
     */
    public static function timeInput(string $name, string $label, string $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->placeholder($default)
            ->default($default)
            ->mask('99:99')
            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
            ->validationMessages(['regex' => 'Nhập giờ dạng 24 giờ, ví dụ 08:00 hoặc 13:30.'])
            ->datalist(static::timeSuggestions())
            ->prefixIcon('heroicon-m-clock')
            ->required(fn (Get $get): bool => ! $get('is_all_day'))
            ->hidden(fn (Get $get): bool => (bool) $get('is_all_day'))
            ->live(onBlur: true);
    }

    /**
     * @return list<string>
     */
    private static function timeSuggestions(): array
    {
        $times = [];

        for ($minutes = 5 * 60; $minutes <= 23 * 60; $minutes += 30) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    /**
     * Dữ liệu form → các cột của lịch và danh sách ê-kíp.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, crew: list<int>}
     */
    public static function toAttributes(array $data): array
    {
        $allDay = (bool) ($data['is_all_day'] ?? false);
        $startDate = (string) ($data['start_date'] ?? today()->toDateString());
        $endDate = (string) ($data['end_date'] ?? $startDate);

        $start = CarbonImmutable::parse(substr($startDate, 0, 10).' '.($allDay ? '00:00' : static::time($data['start_time'] ?? null, static::DEFAULT_START)));
        $end = CarbonImmutable::parse(substr($endDate, 0, 10).' '.($allDay ? '23:59:59' : static::time($data['end_time'] ?? null, static::DEFAULT_END)));

        return [
            'attributes' => [
                'title' => trim((string) ($data['title'] ?? '')),
                'schedule_type_id' => $data['schedule_type_id'] ?? null,
                'status' => $data['status'] ?? ScheduleStatus::Confirmed->value,
                'starts_at' => $start,
                'ends_at' => $end,
                'is_all_day' => $allDay,
                'project_id' => $data['project_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'location' => $data['location'] ?? null,
                'equipment' => $data['equipment'] ?? null,
                'external_crew' => $data['external_crew'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            'crew' => array_values(array_map('intval', (array) ($data['crew'] ?? []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromEntry(ScheduleEntry $entry): array
    {
        return [
            ...$entry->only(['title', 'schedule_type_id', 'project_id', 'customer_id', 'is_all_day', 'location', 'equipment', 'external_crew', 'notes']),
            'status' => $entry->status->value,
            'start_date' => $entry->starts_at->toDateString(),
            'end_date' => $entry->ends_at->toDateString(),
            'start_time' => $entry->is_all_day ? static::DEFAULT_START : $entry->starts_at->format('H:i'),
            'end_time' => $entry->is_all_day ? static::DEFAULT_END : $entry->ends_at->format('H:i'),
            'crew' => $entry->crew()->pluck('users.id')->all(),
        ];
    }

    /**
     * Loại lịch đang dùng. Loại nghỉ phép chỉ dành cho người xếp lịch chọn hộ;
     * người khác dùng nút "Đăng ký nghỉ".
     *
     * @return array<int, string>
     */
    public static function typeOptions(): array
    {
        return ScheduleType::query()->active()->ordered()->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public static function userOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Dự án đang mở mà người dùng được xem (kèm dự án đang chọn, dù đã đóng).
     *
     * @return array<int, string>
     */
    public static function projectOptions(mixed $selected = null): array
    {
        $user = auth()->user();

        if ($user === null || ! Access::can('projects.view')) {
            return [];
        }

        return Project::query()
            ->visibleTo($user)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Project $project): bool => ! $project->isClosed() || (string) $project->getKey() === (string) $selected)
            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => "{$project->code} — {$project->name}"])
            ->all();
    }

    public static function isLeaveType(mixed $typeId): bool
    {
        return filled($typeId) && (bool) ScheduleType::query()->whereKey($typeId)->value('is_leave');
    }

    /**
     * Cảnh báo trùng lịch cho những người đang chọn, theo thời gian đang nhập trên form.
     *
     * @return list<string>
     */
    public static function conflictLines(Get $get, ?int $entryId): array
    {
        return static::conflictsFor([
            'crew' => $get('crew'),
            'status' => $get('status'),
            'start_date' => $get('start_date'),
            'end_date' => $get('end_date'),
            'start_time' => $get('start_time'),
            'end_time' => $get('end_time'),
            'is_all_day' => $get('is_all_day'),
        ], $entryId);
    }

    /**
     * @param  array<string, mixed>  $state  Dữ liệu form (crew, status, ngày, giờ, is_all_day).
     * @param  int|null  $entryId  Lịch đang sửa (không tự trùng với chính nó).
     * @return list<string>
     */
    public static function conflictsFor(array $state, ?int $entryId = null): array
    {
        $crew = (array) ($state['crew'] ?? []);

        if ($crew === [] || ($state['status'] ?? null) === ScheduleStatus::Cancelled->value) {
            return [];
        }

        try {
            ['attributes' => $attributes] = static::toAttributes($state);
        } catch (Throwable) {
            // Ngày giờ đang nhập dở: chưa kiểm tra được.
            return [];
        }

        $conflicts = app(ScheduleConflicts::class);

        return $conflicts->describe($conflicts->find($crew, $attributes['starts_at'], $attributes['ends_at'], $entryId, $attributes['is_all_day']));
    }

    private static function time(mixed $value, string $default): string
    {
        // TimePicker trả "HH:MM" hoặc "HH:MM:SS".
        return is_string($value) && preg_match('/^\d{1,2}:\d{2}/', $value, $matches) === 1 ? $matches[0] : $default;
    }
}
