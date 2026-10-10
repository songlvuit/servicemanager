<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Filament\Pages\WorkCalendar;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Task;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Trang công việc: tiền của công việc (ngân sách, đã cam kết, thực tế, còn
 * lại) ở trên cùng, thông tin công việc bên dưới. Phần tiền chỉ hiện với người
 * xem được chi phí nội bộ.
 */
class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Vượt ngân sách')
                    ->description(fn (Task $record): string => 'Chi phí thực tế '.Money::format($record->actual_cost)
                        .' đã vượt ngân sách '.Money::format($record->budget).'.')
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Task $record): bool => Access::canSeeCosts() && $record->isOverBudget()),

                Section::make('Tiền & ngân sách')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description('Số trước thuế, tính từ đề nghị chi, mua hàng và giờ công gắn vào công việc này.')
                    ->columns(['default' => 2, 'lg' => 4])
                    ->columnSpanFull()
                    ->visible(fn (): bool => Access::canSeeCosts())
                    ->schema([
                        TextEntry::make('budget')
                            ->label('Ngân sách')
                            ->state(fn (Task $record): ?string => $record->hasBudget() ? Money::format($record->budget) : null)
                            ->placeholder('Chưa đặt')
                            ->weight(FontWeight::SemiBold),
                        Columns::moneyEntry('committed_cost', 'Đã cam kết')
                            ->helperText('Đã duyệt / đã đặt hàng, gồm cả phần đã chi.'),
                        Columns::moneyEntry('actual_cost', 'Chi phí thực tế')
                            ->weight(FontWeight::SemiBold)
                            ->color(fn (Task $record): ?string => $record->isOverBudget() ? 'danger' : null)
                            ->helperText('Đã thanh toán, đã nhận hàng, giờ công.'),
                        TextEntry::make('remaining_budget')
                            ->label('Còn lại')
                            ->state(fn (Task $record): ?string => $record->hasBudget() ? Money::format($record->remainingBudget()) : null)
                            ->placeholder('—')
                            ->weight(FontWeight::SemiBold)
                            ->color(fn (Task $record): string => $record->isOverBudget() ? 'danger' : 'success'),
                    ]),

                Section::make('Lịch làm việc')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->description('Lịch làm việc tự lấy theo các thông tin này. Đổi lịch bằng nút "Sửa công việc".')
                    ->columns(['default' => 2, 'lg' => 4])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('schedule_time')
                            ->label('Thời gian')
                            ->state(fn (Task $record): ?string => ($entry = $record->schedule) === null ? null : ($entry->isMultiDay()
                                ? $entry->timeLabel().' ('.$entry->starts_at->format('Y').')'
                                : $entry->starts_at->format('d/m/Y').' · '.$entry->timeLabel()))
                            ->placeholder('Chưa lên lịch (chưa có ngày bắt đầu)')
                            ->weight(FontWeight::SemiBold)
                            ->url(fn (Task $record): ?string => $record->schedule !== null ? WorkCalendar::entryUrl($record->schedule) : null),
                        TextEntry::make('schedule.type.name')
                            ->label('Loại lịch')
                            ->placeholder('—'),
                        TextEntry::make('schedule_crew')
                            ->label('Ê-kíp')
                            ->state(fn (Task $record): ?string => $record->schedule?->crewLabel() ?: null)
                            ->placeholder('—'),
                        TextEntry::make('schedule.status')
                            ->label('Trạng thái lịch')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('location')
                            ->label('Địa điểm')
                            ->placeholder('—'),
                        TextEntry::make('customer_name')
                            ->label('Khách hàng')
                            ->state(fn (Task $record): ?string => $record->project?->customer?->name ?? $record->customer?->name)
                            ->placeholder('—'),
                        TextEntry::make('equipment')
                            ->label('Thiết bị')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),

                Section::make('Thông tin công việc')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->columns(['default' => 2, 'lg' => 4])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('project.name')
                            ->label('Dự án')
                            ->placeholder('Việc độc lập')
                            ->url(fn (Task $record): ?string => $record->project !== null && ProjectResource::canView($record->project)
                                ? ProjectResource::getUrl('view', ['record' => $record->project])
                                : null),
                        TextEntry::make('assignee.name')
                            ->label('Người phụ trách')
                            ->placeholder('Chưa giao'),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('priority')
                            ->label('Mức ưu tiên')
                            ->badge(),
                        TextEntry::make('start_date')
                            ->label('Ngày bắt đầu')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('due_date')
                            ->label('Hạn hoàn thành')
                            ->date()
                            ->placeholder('—')
                            ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null),
                        TextEntry::make('progress')
                            ->label('Tiến độ')
                            ->suffix('%'),
                        TextEntry::make('hours')
                            ->label('Giờ thực tế / dự kiến')
                            ->state(fn (Task $record): string => number_format((float) $record->actual_hours, 1, ',', '.')
                                .' / '.number_format((float) $record->estimated_hours, 1, ',', '.').' giờ'),
                        TextEntry::make('parent.name')
                            ->label('Công việc cha')
                            ->visible(fn (Task $record): bool => $record->parent_id !== null),
                        TextEntry::make('description')
                            ->label('Mô tả')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
