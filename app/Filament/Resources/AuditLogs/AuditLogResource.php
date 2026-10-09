<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Support\Filters;
use App\Filament\Support\VietnameseLabels;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Nhật ký thao tác (mục 39): ai làm gì, trên bản ghi nào, lúc nào, từ đâu.
 * Chỉ để xem — không tạo, sửa hay xoá được, kể cả Super Admin.
 */
class AuditLogResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit-logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'nhật ký thao tác';

    protected static ?string $pluralModelLabel = 'nhật ký thao tác';

    public static function table(Table $table): Table
    {
        return static::logsTable($table)
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Người thực hiện')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')
                    ->label('Hành động')
                    ->options(AuditLog::actionOptions())
                    ->multiple(),
                SelectFilter::make('auditable_type')
                    ->label('Loại bản ghi')
                    ->options(AuditLog::subjectOptions())
                    ->multiple(),
                Filters::dateRange('created_at', 'Thời điểm'),
            ])
            ->emptyStateHeading('Chưa có nhật ký nào.')
            ->emptyStateDescription('Mọi thao tác tạo, sửa, duyệt, thanh toán, xoá và đăng nhập sẽ được ghi lại tại đây.');
    }

    /**
     * Bảng nhật ký dùng chung cho màn hình Audit Log và tab "Hoạt động" của từng bản ghi.
     */
    public static function logsTable(Table $table, bool $withSubject = true, bool $withDetails = true): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời điểm')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('user_name')
                    ->label('Người thực hiện')
                    ->placeholder('Hệ thống')
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('action')
                    ->label('Hành động')
                    ->badge()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->actionLabel())
                    ->color(fn (AuditLog $record): string => $record->actionColor()),
                TextColumn::make('label')
                    ->label('Bản ghi')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable()
                    ->visible($withSubject),
                TextColumn::make('description')
                    ->label('Chi tiết')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('Địa chỉ IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: ! $withSubject)
                    ->visible($withDetails),
            ])
            ->recordActions($withDetails ? [
                ViewAction::make()
                    ->label('Chi tiết')
                    ->modalHeading(fn (AuditLog $record): string => $record->actionLabel().': '.($record->label ?? 'thao tác hệ thống'))
                    ->schema(fn (Schema $schema): Schema => static::infolist($schema)),
            ] : [])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')
                    ->label('Thời điểm')
                    ->dateTime('d/m/Y H:i:s'),
                TextEntry::make('user_name')
                    ->label('Người thực hiện')
                    ->placeholder('Hệ thống'),
                TextEntry::make('action')
                    ->label('Hành động')
                    ->badge()
                    ->formatStateUsing(fn (AuditLog $record): string => $record->actionLabel())
                    ->color(fn (AuditLog $record): string => $record->actionColor()),
                TextEntry::make('label')
                    ->label('Bản ghi')
                    ->placeholder('—'),
                TextEntry::make('description')
                    ->label('Chi tiết')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('ip_address')
                    ->label('Địa chỉ IP')
                    ->placeholder('—'),
                TextEntry::make('user_agent')
                    ->label('Trình duyệt')
                    ->placeholder('—'),
                Section::make('Giá trị thay đổi')
                    ->compact()
                    ->columnSpanFull()
                    ->visible(fn (AuditLog $record): bool => $record->changes() !== [])
                    ->schema([
                        ViewEntry::make('changes')
                            ->hiddenLabel()
                            ->state(fn (AuditLog $record): array => $record->changes())
                            ->view('filament.partials.audit-changes'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
