<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\ScheduleStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Support\Access;
use App\Filament\Support\ContractLink;
use App\Filament\Support\Fields;
use App\Filament\Support\Scheduling\ScheduleForm;
use App\Filament\Support\TaskField;
use App\Models\Customer;
use App\Models\ScheduleType;
use App\Models\Task;
use App\Models\User;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Form công việc, dùng chung cho màn hình Công việc, tab Công việc của hợp đồng
 * và bảng Kanban. Phần "Lịch làm việc" của form quyết định công việc hiện trên
 * Lịch làm việc thế nào. Người chỉ có quyền cập nhật công việc được giao (Employee)
 * chỉ sửa được trạng thái và tiến độ.
 */
class TaskForm
{
    /**
     * @return list<Component>
     */
    public static function components(bool $withContract = true): array
    {
        // Người lập kế hoạch công việc (có quyền tạo) mới đổi được nội dung, người phụ trách, thời hạn.
        $locked = fn (): bool => ! Access::can('tasks.create');

        return array_values(array_filter([
            TextInput::make('name')
                ->label('Tên công việc')
                ->required()
                ->maxLength(255)
                ->disabled($locked)
                ->columnSpanFull(),
            Select::make('assignee_id')
                ->label('Người phụ trách')
                ->options(fn (): array => static::userOptions())
                ->searchable()
                ->live()
                ->disabled($locked),
            Select::make('parent_id')
                ->label('Công việc cha')
                ->options(fn (Get $get, ?Task $record): array => static::parentOptions($get('parent_id'), $record))
                ->searchable()
                ->placeholder('Không có')
                ->helperText('Công việc con luôn theo hợp đồng, báo giá của công việc cha.')
                ->disabled($locked),
            Select::make('priority')
                ->label('Mức ưu tiên')
                ->options(TaskPriority::class)
                ->default(TaskPriority::Medium->value)
                ->required()
                ->disabled($locked),
            Select::make('status')
                ->label('Trạng thái')
                ->options(TaskStatus::class)
                ->default(TaskStatus::Todo->value)
                ->required(),
            static::scheduleSection($locked),
            // Chỉ có trong form của người được xem hợp đồng / báo giá.
            ContractLink::formSection($withContract),
            Fields::zeroWhenBlank(TextInput::make('estimated_hours'))
                ->label('Số giờ dự kiến')
                ->numeric()
                ->minValue(0)
                ->maxValue(9999)
                ->default(0)
                ->suffix('giờ')
                ->disabled($locked),
            Fields::money('budget')
                ->label('Ngân sách')
                ->default(0)
                ->helperText('Trước thuế. Chi phí của công việc (đề nghị chi, mua hàng, giờ công) được so với số này. Để 0 nếu không đặt ngân sách.')
                ->disabled($locked)
                // Ngân sách là thông tin nội bộ, cùng quyền với chi phí.
                ->visible(fn (): bool => Access::canSeeCosts()),
            Fields::zeroWhenBlank(TextInput::make('progress'))
                ->label('Tiến độ')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->default(0)
                ->suffix('%')
                ->helperText('Công việc "Hoàn thành" tự động là 100%.'),
            Textarea::make('description')
                ->label('Mô tả')
                ->rows(3)
                ->disabled($locked)
                ->columnSpanFull(),
        ]));
    }

    /**
     * Dữ liệu của công việc được phép đưa xuống form của người đang đăng nhập:
     * chỉ các cột có ô nhập, và bỏ ngân sách / hợp đồng / báo giá nếu người đó
     * không được xem — để các giá trị này không nằm trong dữ liệu gửi tới trình duyệt.
     *
     * @param  array<string, mixed>  $data  Thuộc tính của công việc.
     * @return array<string, mixed>
     */
    public static function state(array $data): array
    {
        $allowed = array_flip((new Task)->getFillable());

        if (! Access::canSeeCosts()) {
            unset($allowed['budget']);
        }

        if (! ContractLink::canSeeContracts()) {
            unset($allowed['contract_id']);
        }

        if (! ContractLink::canSeeQuotations()) {
            unset($allowed['quotation_id']);
        }

        return array_intersect_key($data, $allowed);
    }

    /**
     * Phần "Lịch làm việc" của công việc. Lịch làm việc không nhập riêng: công
     * việc có ngày bắt đầu tự hiện trên Lịch làm việc theo các thông tin ở đây
     * (xem TaskSchedule).
     */
    private static function scheduleSection(Closure $locked): Section
    {
        return Section::make('Lịch làm việc')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->description('Công việc có ngày bắt đầu sẽ tự hiện trên Lịch làm việc, từ ngày bắt đầu tới hạn hoàn thành (tối đa 2 tháng). Bỏ trống ngày bắt đầu nếu chưa muốn lên lịch.')
            ->compact()
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                DatePicker::make('start_date')
                    ->label('Ngày bắt đầu')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->live()
                    ->disabled($locked),
                DatePicker::make('due_date')
                    ->label('Hạn hoàn thành')
                    ->afterOrEqual('start_date')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->live()
                    ->disabled($locked),
                Select::make('schedule_type_id')
                    ->label('Loại lịch')
                    ->options(fn (): array => static::scheduleTypeOptions())
                    ->default(fn (): ?int => array_key_first(static::scheduleTypeOptions()))
                    ->placeholder('Loại mặc định')
                    ->disabled($locked),
                Toggle::make('is_all_day')
                    ->label('Cả ngày')
                    ->helperText('Tắt để nhập giờ bắt đầu và kết thúc.')
                    ->default(true)
                    ->inline(false)
                    ->live()
                    ->disabled($locked),
                ScheduleForm::timeInput('start_time', 'Từ giờ', ScheduleForm::DEFAULT_START)
                    ->disabled($locked),
                ScheduleForm::timeInput('end_time', 'Đến giờ', ScheduleForm::DEFAULT_END)
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $sameDay = blank($get('due_date')) || substr((string) $get('due_date'), 0, 10) === substr((string) $get('start_date'), 0, 10);

                        if ($sameDay && is_string($value) && $value <= (string) $get('start_time')) {
                            $fail('Giờ kết thúc phải sau giờ bắt đầu.');
                        }
                    })
                    ->disabled($locked),
                Select::make('crew_ids')
                    ->label('Ê-kíp')
                    ->options(fn (): array => static::userOptions())
                    ->multiple()
                    ->searchable()
                    ->placeholder('Chọn thêm người tham gia')
                    ->helperText('Người phụ trách luôn có trong ê-kíp. Cả ê-kíp nhận thông báo và thấy công việc này trong "Lịch của tôi".')
                    ->live()
                    ->disabled($locked)
                    ->columnSpanFull(),
                Callout::make('Trùng lịch')
                    ->description(fn (Get $get, ?Task $record): string => implode("\n", static::conflictLines($get, $record)))
                    ->warning()
                    ->visible(fn (Get $get, ?Task $record): bool => static::conflictLines($get, $record) !== [])
                    ->columnSpanFull(),
                TextInput::make('location')
                    ->label('Địa điểm')
                    ->maxLength(255)
                    ->disabled($locked),
                TextInput::make('external_crew')
                    ->label('Cộng tác viên ngoài')
                    ->placeholder('Ví dụ: 2 quay phim thuê ngoài, 1 trợ lý')
                    ->maxLength(255)
                    ->disabled($locked),
                Textarea::make('equipment')
                    ->label('Thiết bị')
                    ->placeholder('Ví dụ: 2 cam fix, 1 gimbal, 3 mic, phông xanh')
                    ->rows(2)
                    ->maxLength(2000)
                    ->disabled($locked)
                    ->columnSpanFull(),
                Select::make('customer_id')
                    ->label('Khách hàng')
                    ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder('Chưa chọn')
                    ->helperText('Công việc gắn hợp đồng / báo giá thì khách hàng tự theo hợp đồng, báo giá đó.')
                    ->disabled($locked),
                Toggle::make('is_tentative')
                    ->label('Lịch dự kiến (chưa chốt)')
                    ->helperText('Lịch dự kiến hiện viền đứt trên Lịch làm việc để mọi người biết là chưa chốt.')
                    ->inline(false)
                    ->disabled($locked),
            ]);
    }

    /**
     * Cảnh báo trùng lịch cho người phụ trách và ê-kíp, theo ngày giờ đang nhập.
     *
     * @return list<string>
     */
    private static function conflictLines(Get $get, ?Task $record): array
    {
        if (blank($get('start_date')) || in_array($get('status'), [TaskStatus::Cancelled->value, TaskStatus::Cancelled], true)) {
            return [];
        }

        $crew = array_values(array_unique(array_filter([
            $get('assignee_id'),
            ...(array) ($get('crew_ids') ?? []),
        ])));

        return ScheduleForm::conflictsFor([
            'crew' => $crew,
            'status' => ScheduleStatus::Confirmed->value,
            'start_date' => $get('start_date'),
            'end_date' => filled($get('due_date')) ? $get('due_date') : $get('start_date'),
            'start_time' => $get('start_time'),
            'end_time' => $get('end_time'),
            'is_all_day' => $get('is_all_day') ?? true,
        ], $record?->schedule()->value('id'));
    }

    /**
     * Loại lịch công việc đang dùng (không gồm loại nghỉ phép).
     *
     * @return array<int, string>
     */
    public static function scheduleTypeOptions(): array
    {
        return ScheduleType::query()->active()->where('is_leave', false)->ordered()->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public static function userOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Công việc gốc còn mở (trừ chính nó) có thể làm công việc cha; luôn kèm công việc cha đang chọn.
     *
     * @return array<int, string>
     */
    private static function parentOptions(mixed $selected, ?Task $record): array
    {
        return Task::query()
            ->whereNull('parent_id')
            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
            ->where(function (Builder $query) use ($selected): void {
                $query->open();

                if (filled($selected)) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'name', 'start_date'])
            ->mapWithKeys(fn (Task $task): array => [$task->getKey() => TaskField::label($task)])
            ->all();
    }
}
