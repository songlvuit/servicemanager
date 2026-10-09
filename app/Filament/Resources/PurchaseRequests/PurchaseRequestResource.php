<?php

namespace App\Filament\Resources\PurchaseRequests;

use App\Enums\BudgetCategory;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Support\ApprovalTimeline;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\VietnameseLabels;
use App\Models\PurchaseRequest;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
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
 * Yêu cầu mua hàng của nhóm dự án (mục 24).
 */
class PurchaseRequestResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $slug = 'purchase-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Yêu cầu mua hàng';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'yêu cầu mua hàng';

    protected static ?string $pluralModelLabel = 'yêu cầu mua hàng';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin yêu cầu')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->description('Mã yêu cầu (PR-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('project_id')
                            ->label('Dự án')
                            ->options(fn (): array => BudgetItemForm::projectOptions())
                            ->default(fn (): ?int => request()->integer('project') ?: null)
                            ->searchable()
                            ->required(),
                        Select::make('category')
                            ->label('Nhóm chi phí')
                            ->options(BudgetCategory::class)
                            ->default(BudgetCategory::Equipment->value)
                            ->required(),
                        Textarea::make('description')
                            ->label('Nội dung cần mua')
                            ->required()
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Fields::money('amount')
                            ->label('Giá trị ước tính (trước thuế)')
                            ->required()
                            ->minValue(1),
                        DatePicker::make('needed_date')
                            ->label('Ngày cần hàng')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Select::make('supplier_id')
                            ->label('Nhà cung cấp đề xuất')
                            ->options(fn (): array => ExpenseForm::supplierOptions())
                            ->searchable()
                            ->placeholder('Chưa xác định'),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Lý do từ chối')
                    ->description(fn (PurchaseRequest $record): string => (string) $record->rejection_reason)
                    ->danger()
                    ->columnSpanFull()
                    ->visible(fn (PurchaseRequest $record): bool => $record->status === PurchaseRequestStatus::Rejected && filled($record->rejection_reason)),

                Section::make('Thông tin yêu cầu')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã yêu cầu')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('requester.name')
                            ->label('Người yêu cầu'),
                        TextEntry::make('project.name')
                            ->label('Dự án')
                            ->url(fn (PurchaseRequest $record): ?string => $record->project !== null && ProjectResource::canView($record->project)
                                ? ProjectResource::getUrl('view', ['record' => $record->project])
                                : null),
                        TextEntry::make('category')
                            ->label('Nhóm chi phí')
                            ->badge(),
                        TextEntry::make('supplier.name')
                            ->label('Nhà cung cấp đề xuất')
                            ->placeholder('—'),
                        Columns::moneyEntry('amount', 'Giá trị ước tính (trước thuế)')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('needed_date')
                            ->label('Ngày cần hàng')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('purchase_orders')
                            ->label('Đơn mua hàng')
                            ->state(fn (PurchaseRequest $record): ?string => $record->purchaseOrders()->pluck('code')->implode(', ') ?: null)
                            ->placeholder('Chưa lập đơn'),
                        TextEntry::make('description')
                            ->label('Nội dung cần mua')
                            ->columnSpanFull(),
                    ]),

                ApprovalTimeline::section(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Mã'),
                TextColumn::make('description')
                    ->label('Nội dung cần mua')
                    ->description(fn (PurchaseRequest $record): ?string => $record->project?->name)
                    ->limit(70)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('requester.name')
                    ->label('Người yêu cầu')
                    ->toggleable(),
                TextColumn::make('needed_date')
                    ->label('Ngày cần hàng')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                Columns::money('amount', 'Giá trị ước tính', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(PurchaseRequestStatus::class)
                    ->multiple(),
                SelectFilter::make('project_id')
                    ->label('Dự án')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (PurchaseRequest $record): bool => static::canEdit($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có yêu cầu mua hàng nào.')
            ->emptyStateDescription('Nhóm dự án tạo yêu cầu mua hàng; sau khi được duyệt, bộ phận mua hàng lập đơn đặt hàng.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo yêu cầu mua hàng'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequests::route('/'),
            'create' => CreatePurchaseRequest::route('/create'),
            'view' => ViewPurchaseRequest::route('/{record}'),
            'edit' => EditPurchaseRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['project', 'requester']);
    }

    /**
     * Chỉ yêu cầu nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof PurchaseRequest && $record->isEditable() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof PurchaseRequest && $record->isEditable() && parent::canDelete($record);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = PurchaseRequest::query()->awaitingApprovalBy(auth()->user())->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Yêu cầu mua hàng chờ bạn duyệt';
    }
}
