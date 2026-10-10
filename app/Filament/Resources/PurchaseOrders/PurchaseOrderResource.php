<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\TaskField;
use App\Filament\Support\VietnameseLabels;
use App\Models\PurchaseOrder;
use App\Support\Decimal;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
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
 * Đơn mua hàng gửi nhà cung cấp (mục 25).
 */
class PurchaseOrderResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $slug = 'purchase-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Dự án';

    protected static ?string $navigationLabel = 'Đơn mua hàng';

    protected static ?int $navigationSort = 51;

    protected static ?string $modelLabel = 'đơn mua hàng';

    protected static ?string $pluralModelLabel = 'đơn mua hàng';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0);

        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Số đơn')
                            ->weight(FontWeight::SemiBold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('supplier.name')
                            ->label('Nhà cung cấp'),
                        TextEntry::make('project.name')
                            ->label('Dự án')
                            ->placeholder('Không gắn dự án')
                            ->url(fn (PurchaseOrder $record): ?string => $record->project !== null && ProjectResource::canView($record->project)
                                ? ProjectResource::getUrl('view', ['record' => $record->project])
                                : null),
                        TaskField::entry(),
                        TextEntry::make('category')
                            ->label('Nhóm chi phí')
                            ->badge(),
                        TextEntry::make('purchaseRequest.code')
                            ->label('Yêu cầu mua hàng')
                            ->placeholder('Đơn lập trực tiếp')
                            ->url(fn (PurchaseOrder $record): ?string => $record->purchaseRequest !== null
                                ? PurchaseRequestResource::getUrl('view', ['record' => $record->purchaseRequest])
                                : null),
                        TextEntry::make('order_date')
                            ->label('Ngày đặt hàng')
                            ->date(),
                        TextEntry::make('delivery_date')
                            ->label('Ngày giao hàng dự kiến')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('received_at')
                            ->label('Ngày nhận hàng')
                            ->date()
                            ->placeholder('Chưa nhận'),
                    ]),

                Section::make('Dòng hàng')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('Dòng hàng')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Mô tả'),
                                TableColumn::make('Số lượng'),
                                TableColumn::make('Đơn giá'),
                                TableColumn::make('VAT'),
                                TableColumn::make('Thành tiền'),
                            ])
                            ->schema([
                                TextEntry::make('description')->label('Mô tả'),
                                TextEntry::make('quantity')
                                    ->label('Số lượng')
                                    ->formatStateUsing(fn (mixed $state): string => (string) Decimal::trimZeros((string) $state)),
                                TextEntry::make('unit_price')->label('Đơn giá')->formatStateUsing($money),
                                TextEntry::make('tax_rate')
                                    ->label('Thuế VAT')
                                    ->formatStateUsing(fn (mixed $state): string => Money::percent((string) $state)),
                                TextEntry::make('subtotal')->label('Thành tiền')->weight(FontWeight::SemiBold)->formatStateUsing($money),
                            ]),
                    ]),

                Section::make('Tổng hợp')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Columns::moneyEntry('subtotal', 'Thành tiền trước thuế'),
                        Columns::moneyEntry('tax', 'Thuế VAT'),
                        Columns::moneyEntry('total', 'Tổng cộng')
                            ->weight(FontWeight::Bold)
                            ->color('primary'),
                        TextEntry::make('notes')
                            ->label('Ghi chú / điều kiện giao hàng')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Số đơn'),
                TextColumn::make('supplier.name')
                    ->label('Nhà cung cấp')
                    ->description(fn (PurchaseOrder $record): ?string => implode(' · ', array_filter([$record->project?->name, $record->task?->name])) ?: null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('order_date')
                    ->label('Ngày đặt')
                    ->date()
                    ->sortable(),
                TextColumn::make('delivery_date')
                    ->label('Ngày giao')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                Columns::money('total', 'Tổng cộng', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(PurchaseOrderStatus::class)
                    ->multiple(),
                SelectFilter::make('supplier_id')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => ExpenseForm::supplierOptions())
                    ->searchable(),
                SelectFilter::make('project_id')
                    ->label('Dự án')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
                Filters::withoutProject(),
                Filters::dateRange('order_date', 'Ngày đặt hàng'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => static::canEdit($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có đơn mua hàng nào.')
            ->emptyStateDescription('Lập đơn mua hàng từ một yêu cầu mua hàng đã duyệt.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Tạo đơn mua hàng'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'project', 'task']);
    }

    /**
     * Chỉ đơn nháp mới sửa / xoá được — áp dụng cho cả Super Admin.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof PurchaseOrder && $record->isEditable() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof PurchaseOrder && $record->isEditable() && parent::canDelete($record);
    }
}
