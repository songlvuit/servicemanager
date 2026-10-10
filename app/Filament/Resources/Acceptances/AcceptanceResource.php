<?php

namespace App\Filament\Resources\Acceptances;

use App\Enums\AcceptanceStatus;
use App\Enums\ContractStatus;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Acceptances\Pages\CreateAcceptance;
use App\Filament\Resources\Acceptances\Pages\EditAcceptance;
use App\Filament\Resources\Acceptances\Pages\ListAcceptances;
use App\Filament\Resources\Acceptances\Pages\ViewAcceptance;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\VietnameseLabels;
use App\Models\Acceptance;
use App\Models\Contract;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Nghiệm thu (mục 29): biên bản nghiệm thu của hợp đồng, căn cứ để lập hóa đơn.
 */
class AcceptanceResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Acceptance::class;

    protected static ?string $slug = 'acceptances';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Tài chính';

    protected static ?string $navigationLabel = 'Nghiệm thu';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'biên bản nghiệm thu';

    protected static ?string $pluralModelLabel = 'nghiệm thu';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin nghiệm thu')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->description('Số biên bản (NT-năm-số thứ tự) được cấp tự động khi lưu. File biên bản đã ký tải lên ở tab "Tài liệu" sau khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('contract_id')
                            ->label('Hợp đồng')
                            ->options(fn (Get $get): array => static::contractOptions($get('contract_id')))
                            ->default(fn (): ?int => request()->integer('contract') ?: null)
                            ->searchable()
                            ->required()
                            ->helperText('Hợp đồng đang hiệu lực hoặc đã hoàn thành.'),
                        DatePicker::make('acceptance_date')
                            ->label('Ngày nghiệm thu')
                            ->default(fn (): string => today()->toDateString())
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        TextInput::make('description')
                            ->label('Nội dung nghiệm thu')
                            ->placeholder('Ví dụ: Nghiệm thu đợt 1 — bàn giao 10 video bài giảng')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Fields::money('amount')
                            ->label('Giá trị nghiệm thu (trước thuế)')
                            ->required()
                            ->minValue(1),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Lý do từ chối')
                    ->description(fn (Acceptance $record): string => (string) $record->rejection_reason)
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (Acceptance $record): bool => $record->status === AcceptanceStatus::Rejected && filled($record->rejection_reason)),

                Section::make('Thông tin nghiệm thu')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Số biên bản')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('acceptance_date')
                            ->label('Ngày nghiệm thu')
                            ->date(),
                        TextEntry::make('contract.code')
                            ->label('Hợp đồng')
                            ->url(fn (Acceptance $record): ?string => $record->contract !== null && ContractResource::canView($record->contract)
                                ? ContractResource::getUrl('view', ['record' => $record->contract])
                                : null),
                        TextEntry::make('contract.customer.name')
                            ->label('Khách hàng'),
                        Columns::moneyEntry('amount', 'Giá trị nghiệm thu (trước thuế)')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('description')
                            ->label('Nội dung nghiệm thu')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Duyệt & hóa đơn')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label('Người lập')
                            ->placeholder('—'),
                        TextEntry::make('approver.name')
                            ->label('Người duyệt')
                            ->placeholder('—'),
                        TextEntry::make('approved_at')
                            ->label('Ngày duyệt')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('invoice_codes')
                            ->label('Hóa đơn đã lập')
                            ->state(fn (Acceptance $record): ?string => $record->invoices()->pluck('code')->implode(', ') ?: null)
                            ->placeholder('Chưa lập hóa đơn'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Số biên bản'),
                TextColumn::make('description')
                    ->label('Nội dung nghiệm thu')
                    ->description(fn (Acceptance $record): ?string => $record->contract ? $record->contract->code.' · '.$record->contract->customer?->name : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('acceptance_date')
                    ->label('Ngày nghiệm thu')
                    ->date()
                    ->sortable(),
                Columns::money('amount', 'Giá trị', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(AcceptanceStatus::class)
                    ->multiple(),
                SelectFilter::make('contract_id')
                    ->label('Hợp đồng')
                    ->relationship('contract', 'code')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Acceptance $record): bool => static::canEdit($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có biên bản nghiệm thu nào.')
            ->emptyStateDescription('Lập biên bản nghiệm thu khi bàn giao một phần hoặc toàn bộ hợp đồng cho khách hàng.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo biên bản nghiệm thu'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'attachments' => AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcceptances::route('/'),
            'create' => CreateAcceptance::route('/create'),
            'view' => ViewAcceptance::route('/{record}'),
            'edit' => EditAcceptance::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contract.customer']);
    }

    /**
     * Chỉ biên bản nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Acceptance && $record->isEditable() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Acceptance && $record->isEditable() && parent::canDelete($record);
    }

    /**
     * Hợp đồng nghiệm thu được: đang hiệu lực hoặc đã hoàn thành (kèm hợp đồng đang chọn).
     *
     * @return array<int, string>
     */
    private static function contractOptions(mixed $selected = null): array
    {
        return Contract::query()
            ->with('customer')
            ->where(function (Builder $query) use ($selected): void {
                $query->whereIn('status', [ContractStatus::Active->value, ContractStatus::Completed->value]);

                if (filled($selected)) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => "{$contract->code} — {$contract->title} ({$contract->customer?->name})"])
            ->all();
    }
}
