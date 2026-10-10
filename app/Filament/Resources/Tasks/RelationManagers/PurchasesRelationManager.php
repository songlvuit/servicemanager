<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Mua hàng" của công việc: các đơn mua hàng gắn vào công việc (chỉ xem;
 * bấm vào dòng để mở).
 */
class PurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';

    protected static ?string $title = 'Mua hàng';

    protected static ?string $modelLabel = 'đơn mua hàng';

    protected static ?string $pluralModelLabel = 'đơn mua hàng';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedShoppingCart;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('purchases.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('supplier'))
            ->columns([
                Columns::code(label: 'Số đơn'),
                TextColumn::make('supplier.name')
                    ->label('Nhà cung cấp')
                    ->wrap(),
                TextColumn::make('category')
                    ->label('Nhóm')
                    ->badge(),
                TextColumn::make('order_date')
                    ->label('Ngày đặt')
                    ->date()
                    ->sortable(),
                Columns::money('subtotal', 'Trước thuế', summarize: true),
                Columns::money('total', 'Tổng cộng', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createRequest')
                    ->label('Tạo yêu cầu mua hàng')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isCancelled() && PurchaseRequestResource::canCreate())
                    ->url(fn (): string => PurchaseRequestResource::getUrl('create', ['task' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có đơn mua hàng nào.')
            ->emptyStateDescription('Tạo yêu cầu mua hàng; sau khi được duyệt, bộ phận mua hàng lập đơn đặt hàng.');
    }
}
