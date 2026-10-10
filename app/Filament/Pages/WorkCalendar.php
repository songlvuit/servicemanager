<?php

namespace App\Filament\Pages;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Access;
use App\Filament\Support\Scheduling\ScheduleForm;
use App\Models\ScheduleEntry;
use App\Models\ScheduleType;
use App\Models\User;
use App\Services\Scheduling\CalendarQuery;
use App\Services\Scheduling\ScheduleConflicts;
use App\Services\Scheduling\ScheduleManager;
use App\Services\Scheduling\ScheduleTimesheets;
use App\Support\Audit\AuditLogger;
use App\Support\Money;
use App\Support\PublicCalendarLink;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Lịch làm việc chung của công ty: ai làm việc gì, lúc nào, ở đâu, cho dự án
 * nào. Mọi người dùng đều xem được.
 *
 * Trang này CHỈ ĐỂ XEM lịch công việc: lịch tự lấy từ Công việc (công việc có
 * ngày bắt đầu) và mốc của Dự án, nên không có nút thêm / sửa / xoá lịch công
 * việc — việc đó làm ở mục Công việc. Ở đây chỉ còn: đăng ký nghỉ, sửa / xoá
 * lịch nghỉ, ghi giờ công từ lịch và chia sẻ lịch.
 *
 * Bốn cách xem: tuần (sáng / chiều), tháng, theo người, lịch của tôi.
 */
class WorkCalendar extends Page
{
    public const VIEWS = [
        'week' => 'Tuần',
        'month' => 'Tháng',
        'people' => 'Theo người',
        'mine' => 'Lịch của tôi',
    ];

    protected static ?string $slug = 'calendar';

    protected static ?string $title = 'Lịch làm việc';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.calendar.index';

    #[Url(as: 'view')]
    public string $mode = 'week';

    /** Ngày neo (Y-m-d): tuần / tháng đang xem là tuần / tháng chứa ngày này. */
    #[Url]
    public ?string $date = null;

    #[Url]
    public ?string $type = null;

    #[Url]
    public ?string $person = null;

    #[Url]
    public ?string $project = null;

    #[Url]
    public bool $cancelled = false;

    public function mount(): void
    {
        $this->mode = array_key_exists($this->mode, self::VIEWS) ? $this->mode : 'week';
        $this->date = $this->anchor()->toDateString();
    }

    /**
     * Số lịch hôm nay của người đang đăng nhập.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $today = ScheduleEntry::query()
            ->occupying()
            ->forUser($user)
            ->between(today()->startOfDay(), today()->endOfDay())
            ->whereHas('type', fn ($query) => $query->where('is_leave', false))
            ->count();

        return $today > 0 ? (string) $today : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lịch của bạn hôm nay';
    }

    /**
     * Đường dẫn mở lịch đúng tuần của một lịch và bật cửa sổ chi tiết của nó.
     */
    public static function entryUrl(ScheduleEntry $entry): string
    {
        return static::getUrl([
            'date' => $entry->starts_at->toDateString(),
            'action' => 'viewEntry',
            'actionArguments' => ['entry' => $entry->getKey()],
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Lịch chung của cả công ty, tự lấy từ Công việc và Dự án. Muốn thêm hoặc đổi lịch, hãy tạo / sửa công việc ở mục Công việc.';
    }

    // --- Điều hướng ---------------------------------------------------------

    public function anchor(): CarbonImmutable
    {
        try {
            return filled($this->date) ? CarbonImmutable::parse($this->date)->startOfDay() : CarbonImmutable::today();
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }

    public function previous(): void
    {
        $this->shift(-1);
    }

    public function next(): void
    {
        $this->shift(1);
    }

    public function goToToday(): void
    {
        $this->date = today()->toDateString();
    }

    public function setMode(string $mode): void
    {
        if (array_key_exists($mode, self::VIEWS)) {
            $this->mode = $mode;
        }
    }

    /**
     * Bấm vào một ngày trên lưới tháng: mở tuần chứa ngày đó.
     */
    public function openDay(string $date): void
    {
        $this->date = $date;
        $this->date = $this->anchor()->toDateString();
        $this->mode = 'week';
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'person', 'project', 'cancelled');
    }

    private function shift(int $direction): void
    {
        $anchor = $this->anchor();

        $this->date = match ($this->mode) {
            'month' => $anchor->startOfMonth()->addMonthsNoOverflow($direction)->toDateString(),
            'mine' => $anchor->addDays(30 * $direction)->toDateString(),
            default => $anchor->addWeeks($direction)->toDateString(),
        };
    }

    // --- Dữ liệu cho giao diện ----------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(CalendarQuery::class);
        $user = auth()->user();
        $anchor = $this->anchor();
        $filters = $this->filters();

        $data = match ($this->mode) {
            'month' => $query->month($anchor, $filters),
            'people' => $query->people($anchor, $filters),
            'mine' => [
                'label' => 'Từ '.$anchor->format('d/m/Y').' · 30 ngày',
                'agenda' => $query->agenda($user, $anchor, 30),
                'conflicts' => app(ScheduleConflicts::class)->among($query->entries($anchor, $anchor->addDays(29)->endOfDay())),
            ],
            default => $query->week($anchor, $filters, $user),
        };

        return [
            'data' => $data,
            'views' => self::VIEWS,
            'types' => ScheduleType::query()->active()->ordered()->get(),
            'people' => ScheduleForm::userOptions(),
            'projects' => ScheduleForm::projectOptions($this->project),
            'hasFilters' => filled($this->type) || filled($this->person) || filled($this->project) || $this->cancelled,
        ];
    }

    /**
     * @return array{type: ?string, user: ?string, project: ?string, cancelled: bool}
     */
    private function filters(): array
    {
        return [
            'type' => $this->type ?: null,
            'user' => $this->person ?: null,
            'project' => $this->project ?: null,
            'cancelled' => $this->cancelled,
        ];
    }

    // --- Thao tác -----------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->registerLeaveAction(),
            ActionGroup::make([
                $this->subscribeAction(),
                $this->publicLinkAction(),
            ])
                ->label('Chia sẻ')
                ->icon(Heroicon::OutlinedShare)
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Chi tiết một lịch (ai cũng mở được). Nút Sửa / Ghi giờ công / Xoá hiện theo quyền.
     */
    public function viewEntryAction(): Action
    {
        return Action::make('viewEntry')
            ->modalHeading(fn (array $arguments): string => $this->findEntry($arguments)?->title ?? 'Lịch không còn tồn tại')
            ->modalDescription(fn (array $arguments): ?string => $this->findEntry($arguments)?->type?->name)
            ->modalWidth(Width::TwoExtraLarge)
            ->record(fn (array $arguments): ?ScheduleEntry => $this->findEntry($arguments))
            ->schema(fn (array $arguments): array => $this->entryDetails($this->findEntry($arguments)))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->extraModalFooterActions(function (array $arguments): array {
                $entry = $this->findEntry($arguments);
                $user = auth()->user();

                if ($entry === null || $user === null) {
                    return [];
                }

                $open = fn (string $action): \Closure => fn () => $this->replaceMountedAction($action, ['entry' => $entry->getKey()]);

                return [
                    // Lịch công việc là bản chiếu của công việc: xem và sửa ở trang công việc.
                    Action::make('task')
                        ->label('Mở công việc')
                        ->icon(Heroicon::OutlinedRectangleStack)
                        ->visible($this->taskUrl($entry) !== null)
                        ->url($this->taskUrl($entry)),
                    Action::make('edit')
                        ->label('Sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->visible($entry->isLeave() && $user->can('update', $entry))
                        ->action($open('editEntry')),
                    Action::make('timesheets')
                        ->label('Ghi giờ công')
                        ->icon(Heroicon::OutlinedClock)
                        ->color('gray')
                        ->visible($this->canLogTimesheets($entry))
                        ->action($open('logTimesheets')),
                    Action::make('delete')
                        ->label('Xoá')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible($entry->isLeave() && $user->can('delete', $entry))
                        ->action($open('deleteEntry')),
                ];
            });
    }

    public function editEntryAction(): Action
    {
        return Action::make('editEntry')
            ->modalHeading('Sửa lịch nghỉ')
            ->modalSubmitActionLabel('Lưu lịch')
            ->modalWidth(Width::ThreeExtraLarge)
            ->record(fn (array $arguments): ?ScheduleEntry => $this->findEntry($arguments))
            ->fillForm(fn (array $arguments): array => ($entry = $this->findEntry($arguments)) ? ScheduleForm::fromEntry($entry) : [])
            // Chỉ còn lịch nghỉ sửa được ở đây, và chỉ đổi thời gian, ghi chú.
            ->schema(fn (array $arguments): array => ScheduleForm::components(
                entryId: $this->findEntry($arguments)?->getKey(),
                restricted: true,
            ))
            ->action(function (array $data, array $arguments): void {
                $this->guarded(function () use ($data, $arguments): void {
                    $entry = $this->leaveEntry($arguments);

                    // Trường bị khoá không được gửi lên: giữ giá trị đang có.
                    ['attributes' => $attributes, 'crew' => $crew] = ScheduleForm::toAttributes([...ScheduleForm::fromEntry($entry), ...$data]);

                    $entry = app(ScheduleManager::class)->save($entry, $attributes, $crew, auth()->user());

                    $this->notifySaved($entry, 'Đã lưu lịch.');
                });
            });
    }

    public function deleteEntryAction(): Action
    {
        return Action::make('deleteEntry')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(fn (array $arguments): string => 'Xoá lịch "'.($this->findEntry($arguments)?->title ?? '').'"?')
            ->modalDescription('Lịch nghỉ sẽ bị xoá hẳn khỏi lịch chung.')
            ->modalSubmitActionLabel('Xoá lịch')
            ->action(function (array $arguments): void {
                $this->guarded(function () use ($arguments): void {
                    app(ScheduleManager::class)->delete($this->leaveEntry($arguments), auth()->user());

                    Notification::make()->title('Đã xoá lịch.')->success()->send();
                });
            });
    }

    /**
     * Ghi giờ công cho cả ê-kíp từ một lịch đã diễn ra.
     */
    public function logTimesheetsAction(): Action
    {
        return Action::make('logTimesheets')
            ->modalHeading('Ghi giờ công từ lịch')
            ->modalDescription(function (array $arguments): string {
                $entry = $this->findEntry($arguments);

                return $entry === null ? '' : implode(' · ', array_filter([
                    $entry->title,
                    $entry->project?->code ?? 'Không gắn dự án',
                    'ê-kíp: '.$entry->crew->pluck('name')->implode(', '),
                ]));
            })
            ->modalSubmitActionLabel('Ghi giờ công')
            ->modalWidth(Width::Large)
            ->fillForm(fn (array $arguments): array => [
                'hours' => ($entry = $this->findEntry($arguments)) ? app(ScheduleTimesheets::class)->suggestedHours($entry) : null,
            ])
            ->schema([
                TextInput::make('hours')
                    ->label('Số giờ mỗi người, mỗi ngày')
                    ->numeric()
                    ->minValue(0.25)
                    ->maxValue(24)
                    ->required()
                    ->suffix('giờ')
                    ->helperText('Mỗi người trong ê-kíp được ghi số giờ này cho từng ngày của lịch, vào dự án của lịch (nếu có). Người / ngày đã ghi rồi sẽ được bỏ qua. Lịch chuyển sang "Đã xong".'),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->guarded(function () use ($data, $arguments): void {
                    $entry = $this->findEntry($arguments);

                    if ($entry === null) {
                        throw new WorkflowException('Lịch không còn tồn tại.');
                    }

                    $created = app(ScheduleTimesheets::class)->log($entry, auth()->user(), $data['hours']);

                    Notification::make()
                        ->title($created > 0 ? "Đã ghi {$created} dòng giờ công." : 'Giờ công của lịch này đã được ghi từ trước.')
                        ->body(match (true) {
                            $created === 0 => null,
                            $entry->project !== null => 'Chi phí nhân sự đã được cộng vào dự án '.$entry->project->code.'.',
                            default => 'Lịch không gắn dự án nên chi phí nhân sự được tính là chi phí chung.',
                        })
                        ->success()
                        ->send();
                });
            });
    }

    /**
     * Ai cũng tự đăng ký được lịch nghỉ của chính mình.
     */
    public function registerLeaveAction(): Action
    {
        return Action::make('registerLeave')
            ->label('Đăng ký nghỉ')
            ->icon(Heroicon::OutlinedMoon)
            ->color('gray')
            ->modalHeading('Đăng ký nghỉ')
            ->modalDescription('Lịch nghỉ hiện trên lịch chung, để người giao việc biết và không xếp việc trùng.')
            ->modalSubmitActionLabel('Đăng ký')
            ->modalWidth(Width::Large)
            ->fillForm(fn (): array => ['start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDay()->toDateString()])
            ->schema([
                Select::make('user_id')
                    ->label('Người nghỉ')
                    ->options(fn (): array => ScheduleForm::userOptions())
                    ->default(fn (): ?int => auth()->id())
                    ->searchable()
                    ->required()
                    // Người xếp lịch đăng ký hộ được cho người khác.
                    ->visible(fn (): bool => Access::can('schedules.create')),
                DatePicker::make('start_date')
                    ->label('Từ ngày')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
                DatePicker::make('end_date')
                    ->label('Đến hết ngày')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterOrEqual('start_date')
                    ->required(),
                Textarea::make('notes')
                    ->label('Lý do / ghi chú')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $this->guarded(function () use ($data): void {
                    $person = Access::can('schedules.create') && filled($data['user_id'] ?? null)
                        ? User::query()->where('is_active', true)->findOrFail($data['user_id'])
                        : auth()->user();

                    $entry = app(ScheduleManager::class)->registerLeave(
                        $person,
                        CarbonImmutable::parse($data['start_date'])->startOfDay(),
                        CarbonImmutable::parse($data['end_date'])->endOfDay(),
                        allDay: true,
                        note: $data['notes'] ?? null,
                    );

                    $this->notifySaved($entry, 'Đã đăng ký nghỉ.');
                });
            });
    }

    /**
     * Đường dẫn đăng ký lịch cá nhân vào Google Calendar / lịch điện thoại.
     */
    public function subscribeAction(): Action
    {
        return Action::make('subscribe')
            ->label('Đưa lịch của tôi vào điện thoại')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->modalHeading('Đưa lịch của tôi vào Google Calendar / điện thoại')
            ->modalDescription('Dán đường dẫn dưới đây vào ứng dụng lịch (Google Calendar: "Thêm lịch khác → Từ URL"; iPhone: "Thêm lịch đã đăng ký"). Ứng dụng tự cập nhật khi lịch thay đổi, thường chậm vài giờ.')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(fn (): array => [
                TextEntry::make('personal_feed')
                    ->label('Đường dẫn lịch của riêng bạn')
                    ->state(route('calendar.feed', ['token' => auth()->user()->calendarToken()]))
                    ->copyable()
                    ->copyMessage('Đã chép đường dẫn')
                    ->fontFamily('mono')
                    ->helperText('Ai có đường dẫn này đều xem được lịch của bạn — đừng chia sẻ cho người khác.'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->extraModalFooterActions([
                Action::make('rotatePersonalFeed')
                    ->label('Đổi đường dẫn')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Đường dẫn cũ sẽ ngừng hoạt động; các ứng dụng lịch đã đăng ký phải được đăng ký lại bằng đường dẫn mới.')
                    ->action(function (): void {
                        auth()->user()->rotateCalendarToken();

                        Notification::make()->title('Đã đổi đường dẫn lịch của bạn.')->success()->send();

                        $this->replaceMountedAction('subscribe');
                    }),
            ]);
    }

    /**
     * Đường dẫn chỉ-xem của lịch chung, không cần đăng nhập.
     */
    public function publicLinkAction(): Action
    {
        return Action::make('publicLink')
            ->label('Đường dẫn chỉ-xem (không cần đăng nhập)')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (): bool => Access::can('schedules.update'))
            ->modalHeading('Đường dẫn chỉ-xem của lịch chung')
            ->modalDescription('Ai có đường dẫn này đều xem được lịch mà không cần tài khoản — dùng để dán vào nhóm chat hoặc mở trên màn hình văn phòng. Trang chỉ để xem, không sửa được gì.')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(fn (): array => PublicCalendarLink::isEnabled()
                ? [
                    TextEntry::make('public_url')
                        ->label('Đường dẫn xem lịch')
                        ->state(PublicCalendarLink::url())
                        ->copyable()
                        ->copyMessage('Đã chép đường dẫn')
                        ->fontFamily('mono'),
                    TextEntry::make('public_feed')
                        ->label('Đường dẫn để đăng ký vào ứng dụng lịch')
                        ->state(PublicCalendarLink::feedUrl())
                        ->copyable()
                        ->copyMessage('Đã chép đường dẫn')
                        ->fontFamily('mono'),
                ]
                : [
                    Callout::make('Đang tắt')
                        ->description('Hiện chỉ người đã đăng nhập mới xem được lịch. Bấm "Bật đường dẫn" để tạo một đường dẫn chỉ-xem.')
                        ->info(),
                ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            // Ba nút, hiện theo tình trạng lúc vẽ cửa sổ (closure) để luôn đúng sau khi vừa bật / tắt.
            ->extraModalFooterActions([
                Action::make('enablePublicLink')
                    ->label('Bật đường dẫn')
                    ->visible(fn (): bool => ! PublicCalendarLink::isEnabled())
                    ->action(fn () => $this->changePublicLink('enable')),
                Action::make('rotatePublicLink')
                    ->label('Đổi đường dẫn')
                    ->color('gray')
                    ->visible(fn (): bool => PublicCalendarLink::isEnabled())
                    ->requiresConfirmation()
                    ->modalHeading('Đổi đường dẫn chỉ-xem?')
                    ->modalDescription('Đường dẫn cũ sẽ ngừng hoạt động ngay; phải gửi lại đường dẫn mới cho mọi người.')
                    ->action(fn () => $this->changePublicLink('rotate')),
                Action::make('disablePublicLink')
                    ->label('Tắt đường dẫn')
                    ->color('danger')
                    ->visible(fn (): bool => PublicCalendarLink::isEnabled())
                    ->requiresConfirmation()
                    ->modalHeading('Tắt đường dẫn chỉ-xem?')
                    ->modalDescription('Sau khi tắt, không ai xem được lịch nếu chưa đăng nhập.')
                    ->action(fn () => $this->changePublicLink('disable')),
            ]);
    }

    /**
     * Bật, đổi mã hoặc tắt đường dẫn chỉ-xem; ghi nhật ký và mở lại cửa sổ để hiện tình trạng mới.
     *
     * @param  'enable'|'rotate'|'disable'  $change
     */
    private function changePublicLink(string $change): void
    {
        abort_unless(Access::can('schedules.update'), 403);

        $enabled = PublicCalendarLink::isEnabled();

        // Bấm "Bật" khi đã bật (hai người cùng thao tác) thì giữ nguyên đường dẫn đang dùng.
        if ($change === 'enable' && $enabled) {
            $this->replaceMountedAction('publicLink');

            return;
        }

        [$audit, $message] = match ($change) {
            'disable' => ['Tắt đường dẫn chỉ-xem của lịch', 'Đã tắt đường dẫn chỉ-xem.'],
            'rotate' => ['Đổi đường dẫn chỉ-xem của lịch', 'Đã đổi đường dẫn chỉ-xem.'],
            default => ['Bật đường dẫn chỉ-xem của lịch', 'Đã bật đường dẫn chỉ-xem.'],
        };

        $change === 'disable' ? PublicCalendarLink::disable() : PublicCalendarLink::rotate();

        AuditLogger::record('settings_updated', label: 'Lịch làm việc', description: $audit);
        Notification::make()->title($message)->success()->send();

        $this->replaceMountedAction('publicLink');
    }

    // --- Hỗ trợ -------------------------------------------------------------

    public function canLogTimesheets(ScheduleEntry $entry): bool
    {
        return ! $entry->isLeave()
            && $entry->status->occupiesTime()
            && $entry->starts_at->isPast()
            && (auth()->user()?->can('logTimesheets', $entry) ?? false);
    }

    /**
     * Lịch nghỉ đang được sửa / xoá từ trang Lịch. Lịch công việc không sửa
     * được ở đây: nó đi theo công việc.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function leaveEntry(array $arguments): ScheduleEntry
    {
        $entry = $this->findEntry($arguments);

        if ($entry === null) {
            throw new WorkflowException('Lịch không còn tồn tại.');
        }

        if (! $entry->isLeave()) {
            throw new WorkflowException('Lịch này lấy từ công việc. Hãy sửa hoặc xoá công việc ở mục Công việc.');
        }

        return $entry;
    }

    /**
     * Trang công việc của lịch (nơi quản lý chi phí và ngân sách), nếu người dùng mở được.
     */
    private function taskUrl(ScheduleEntry $entry): ?string
    {
        return $entry->task !== null && (auth()->user()?->can('view', $entry->task) ?? false)
            ? TaskResource::getUrl('view', ['record' => $entry->task])
            : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findEntry(array $arguments): ?ScheduleEntry
    {
        $id = (int) ($arguments['entry'] ?? 0);

        return $id > 0
            ? ScheduleEntry::query()->with(['type', 'crew', 'project', 'customer', 'creator', 'task'])->find($id)
            : null;
    }

    /**
     * Nội dung cửa sổ chi tiết của một lịch.
     *
     * @return list<Component>
     */
    private function entryDetails(?ScheduleEntry $entry): array
    {
        if ($entry === null) {
            return [Callout::make('Lịch này đã bị xoá.')->warning()];
        }

        $conflicts = app(ScheduleConflicts::class);
        $lines = $conflicts->describe($conflicts->forEntry($entry));
        $text = fn (string $name, string $label, ?string $state): TextEntry => TextEntry::make($name)
            ->label($label)
            ->state($state)
            ->placeholder('—');

        return [
            Callout::make('Trùng lịch')
                ->description(implode("\n", $lines))
                ->warning()
                ->visible($lines !== [])
                ->columnSpanFull(),
            $text('when', 'Thời gian', $entry->isMultiDay() ? $entry->timeLabel().' ('.$entry->starts_at->format('Y').')' : $entry->starts_at->format('d/m/Y').' · '.$entry->timeLabel())
                ->weight(FontWeight::SemiBold),
            TextEntry::make('status')
                ->label('Trạng thái')
                ->state($entry->status)
                ->badge(),
            $text('crew', $entry->isLeave() ? 'Người nghỉ' : 'Ê-kíp', $entry->crewLabel() ?: null)
                ->columnSpanFull(),
            TextEntry::make('project')
                ->label('Dự án')
                ->state($entry->project ? $entry->project->code.' — '.$entry->project->name : null)
                ->url($entry->project !== null && ProjectResource::canView($entry->project)
                    ? ProjectResource::getUrl('view', ['record' => $entry->project])
                    : null)
                ->placeholder('Không gắn dự án')
                ->hidden($entry->isLeave()),
            $text('customer', 'Khách hàng', $entry->customer?->name)
                ->hidden($entry->isLeave()),
            TextEntry::make('task')
                ->label('Công việc')
                ->state($entry->task?->name)
                ->url($this->taskUrl($entry))
                ->placeholder('Chưa có')
                ->hidden($entry->isLeave()),
            $text('task_money', 'Chi phí / ngân sách', $entry->task === null ? null : Money::format($entry->task->actual_cost)
                .' / '.($entry->task->hasBudget() ? Money::format($entry->task->budget) : 'chưa đặt ngân sách'))
                ->color($entry->task?->isOverBudget() ? 'danger' : null)
                ->hidden($entry->isLeave() || $entry->task === null || ! Access::canSeeCosts()),
            $text('location', 'Địa điểm', $entry->location)
                ->hidden($entry->isLeave())
                ->columnSpanFull(),
            $text('equipment', 'Thiết bị', $entry->equipment)
                ->hidden($entry->isLeave() || blank($entry->equipment))
                ->columnSpanFull(),
            $text('notes', 'Ghi chú', $entry->notes)
                ->hidden(blank($entry->notes))
                ->columnSpanFull(),
            $text('timesheets', 'Giờ công', $entry->timesheets_logged_at ? 'Đã ghi lúc '.$entry->timesheets_logged_at->format('d/m/Y H:i') : null)
                ->hidden($entry->timesheets_logged_at === null),
            $text('creator', 'Người tạo', $entry->creator?->name)
                ->color('gray'),
        ];
    }

    /**
     * Chạy một thao tác ghi và đổi lỗi nghiệp vụ / lỗi quyền thành thông báo.
     */
    private function guarded(\Closure $callback): void
    {
        try {
            $callback();
        } catch (WorkflowException|AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    /**
     * Báo đã lưu; nếu lịch gây trùng thì báo luôn ai bị trùng.
     */
    private function notifySaved(ScheduleEntry $entry, string $title): void
    {
        $conflicts = app(ScheduleConflicts::class);
        $lines = $entry->isLeave() ? [] : $conflicts->describe($conflicts->forEntry($entry));

        if ($lines === []) {
            Notification::make()->title($title)->success()->send();

            return;
        }

        Notification::make()
            ->title($title.' Có người bị trùng lịch:')
            ->body(implode("\n", $lines))
            ->warning()
            ->persistent()
            ->send();
    }

    private function safeDate(mixed $date): ?string
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
