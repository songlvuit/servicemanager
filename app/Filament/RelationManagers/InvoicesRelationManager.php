<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Hóa đơn" dùng chung cho khách hàng và hợp đồng (chỉ xem; bấm
 * vào dòng để mở hóa đơn).
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Hóa đơn';

    protected static ?string $modelLabel = 'hóa đơn';

    protected static ?string $pluralModelLabel = 'hóa đơn';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedReceiptPercent;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('invoices.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Columns::code(label: 'Số hóa đơn'),
                TextColumn::make('description')
                    ->label('Nội dung')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('due_date')
                    ->label('Hạn thanh toán')
                    ->date()
                    ->color(fn (Invoice $record): ?string => $record->isPastDue() ? 'danger' : null)
                    ->sortable(),
                Columns::money('total', 'Tổng tiền', summarize: true),
                Columns::money('paid_amount', 'Đã thu', summarize: true),
                Columns::money('remaining_amount', 'Còn phải thu', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createInvoice')
                    ->label('Tạo hóa đơn')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->trashed() && InvoiceResource::canCreate())
                    ->url(fn (): string => InvoiceResource::getUrl('create', $this->createParameters())),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->emptyStateHeading('Chưa có hóa đơn nào.')
            ->emptyStateDescription('Hóa đơn đã lập sẽ hiển thị tại đây.');
    }

    /**
     * Tham số để form hóa đơn điền sẵn theo trang đang mở.
     *
     * @return array<string, int|string>
     */
    private function createParameters(): array
    {
        $owner = $this->getOwnerRecord();

        return match (true) {
            $owner instanceof Contract => ['contract' => $owner->getKey()],
            $owner instanceof Customer => ['customer' => $owner->getKey()],
            default => [],
        };
    }
}
