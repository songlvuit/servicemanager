<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use UnitEnum;

/**
 * Hệ thống → Thông báo (mục 38): toàn bộ thông báo của người đang đăng nhập —
 * cùng nội dung với chuông trên thanh trên cùng, nhưng có tìm kiếm, lọc và
 * giữ lại cả thông báo đã đọc.
 */
class Notifications extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'notifications';

    protected static ?string $title = 'Thông báo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $unread = static::baseQuery()->whereNull('read_at')->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Thông báo chưa đọc';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllAsRead')
                ->label('Đánh dấu tất cả đã đọc')
                ->icon(Heroicon::OutlinedCheck)
                ->color('gray')
                ->visible(fn (): bool => static::baseQuery()->whereNull('read_at')->exists())
                ->action(function (): void {
                    static::baseQuery()->whereNull('read_at')->update(['read_at' => now()]);

                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::baseQuery())
            ->columns([
                IconColumn::make('status')
                    ->label('')
                    ->state(fn (DatabaseNotification $record): string => (string) ($record->data['status'] ?? 'info'))
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'success' => Heroicon::OutlinedCheckCircle,
                        'warning' => Heroicon::OutlinedExclamationTriangle,
                        'danger' => Heroicon::OutlinedExclamationCircle,
                        default => Heroicon::OutlinedInformationCircle,
                    })
                    ->color(fn (string $state): string => in_array($state, ['success', 'warning', 'danger'], true) ? $state : 'info')
                    ->grow(false),
                TextColumn::make('title')
                    ->label('Thông báo')
                    ->state(fn (DatabaseNotification $record): string => strip_tags((string) ($record->data['title'] ?? 'Thông báo')))
                    ->description(fn (DatabaseNotification $record): string => strip_tags((string) ($record->data['body'] ?? '')))
                    ->weight(fn (DatabaseNotification $record): FontWeight => $record->read_at === null ? FontWeight::SemiBold : FontWeight::Normal)
                    ->wrap(),
                TextColumn::make('read_at')
                    ->label('Trạng thái')
                    ->state(fn (DatabaseNotification $record): string => $record->read_at === null ? 'Chưa đọc' : 'Đã đọc')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Chưa đọc' ? 'primary' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Thời điểm')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (DatabaseNotification $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('read_at')
                    ->label('Tình trạng')
                    ->placeholder('Tất cả')
                    ->trueLabel('Chưa đọc')
                    ->falseLabel('Đã đọc')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('read_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('read_at'),
                    ),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Mở')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (DatabaseNotification $record): bool => static::targetUrl($record) !== null)
                    ->action(function (DatabaseNotification $record): void {
                        $record->markAsRead();

                        $this->redirect((string) static::targetUrl($record));
                    }),
                Action::make('markAsRead')
                    ->label('Đã đọc')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->visible(fn (DatabaseNotification $record): bool => $record->read_at === null)
                    ->action(fn (DatabaseNotification $record) => $record->markAsRead()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedBellSlash)
            ->emptyStateHeading('Chưa có thông báo nào.')
            ->emptyStateDescription('Chứng từ cần bạn duyệt, hóa đơn tới hạn, công việc vượt ngân sách… sẽ được báo tại đây.');
    }

    /**
     * Thông báo của người đang đăng nhập (đúng những thông báo chuông hiển thị).
     *
     * @return Builder<DatabaseNotification>
     */
    public static function baseQuery(): Builder
    {
        $user = auth()->user();

        return DatabaseNotification::query()
            ->where('notifiable_type', $user?->getMorphClass())
            ->where('notifiable_id', $user?->getKey())
            ->where('data->format', 'filament');
    }

    /**
     * Đường dẫn của nút đầu tiên trong thông báo — chỉ nhận đường dẫn nội bộ.
     */
    public static function targetUrl(DatabaseNotification $notification): ?string
    {
        $url = $notification->data['actions'][0]['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $internal = str_starts_with($url, '/') && ! str_starts_with($url, '//');

        return $internal || str_starts_with($url, rtrim((string) config('app.url'), '/').'/') || str_starts_with($url, url('/'))
            ? $url
            : null;
    }
}
