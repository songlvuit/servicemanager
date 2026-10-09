<?php

namespace App\Filament\RelationManagers;

use App\Models\Attachment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Tab "Tài liệu" dùng chung cho mọi chứng từ có đính kèm file (mục 47):
 * khách hàng, báo giá, hợp đồng, dự án, chi phí, hóa đơn, nghiệm thu.
 *
 * Xem / tải: ai xem được chứng từ. Tải lên / xoá: policy `manageAttachments`
 * của chứng từ. Định dạng và dung lượng cho phép lấy từ config/uploads.php.
 */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Tài liệu';

    protected static ?string $modelLabel = 'tài liệu';

    protected static ?string $pluralModelLabel = 'tài liệu';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPaperClip;

    public const CATEGORIES = [
        'Hợp đồng' => 'Hợp đồng',
        'Phụ lục' => 'Phụ lục',
        'Biên bản' => 'Biên bản',
        'Hóa đơn' => 'Hóa đơn',
        'Biên nhận' => 'Biên nhận',
        'Hình ảnh' => 'Hình ảnh',
        'Tài liệu khác' => 'Tài liệu khác',
    ];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        $maxSizeMb = (int) config('uploads.max_size_mb', 20);

        return $schema
            ->columns(1)
            ->components([
                FileUpload::make('path')
                    ->label('File')
                    ->required()
                    ->disk(config('uploads.disk'))
                    ->directory('attachments/'.now()->format('Y/m'))
                    ->visibility('private')
                    ->acceptedFileTypes(config('uploads.mime_types'))
                    ->maxSize($maxSizeMb * 1024)
                    ->storeFileNamesIn('filename')
                    ->helperText('Cho phép: '.implode(', ', config('uploads.extensions'))." — tối đa {$maxSizeMb}MB."),
                Select::make('category')
                    ->label('Loại tài liệu')
                    ->options(self::CATEGORIES)
                    ->default('Tài liệu khác')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('filename')
            ->columns([
                TextColumn::make('filename')
                    ->label('Tên file')
                    ->icon(Heroicon::OutlinedDocument)
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Loại')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label('Dung lượng')
                    ->state(fn (Attachment $record): string => $record->humanSize())
                    ->alignEnd(),
                TextColumn::make('uploader.name')
                    ->label('Người tải lên')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Ngày tải lên')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tải lên')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalHeading('Tải tài liệu lên')
                    ->createAnother(false)
                    ->visible(fn (): bool => $this->canManage())
                    ->mutateDataUsing(function (array $data): array {
                        $disk = Storage::disk(config('uploads.disk'));

                        return [
                            ...$data,
                            'disk' => config('uploads.disk'),
                            'mime_type' => $disk->mimeType($data['path']) ?: null,
                            'size' => $disk->size($data['path']),
                        ];
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (Attachment $record): string => $record->isPreviewable() ? 'Xem' : 'Tải về')
                    ->icon(fn (Attachment $record): Heroicon => $record->isPreviewable() ? Heroicon::OutlinedEye : Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Attachment $record): string => $record->url(), shouldOpenInNewTab: true),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canManage()),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có tài liệu nào.')
            ->emptyStateDescription('Hợp đồng, phụ lục, biên bản, hóa đơn, hình ảnh... tải lên sẽ hiển thị tại đây.');
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('manageAttachments', $this->getOwnerRecord()) ?? false;
    }
}
