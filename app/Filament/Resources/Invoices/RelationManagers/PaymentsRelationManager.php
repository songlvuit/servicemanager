<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Access;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Thanh toán" của hóa đơn và của khách hàng: các lần khách đã thanh toán
 * (chỉ xem — thanh toán đã ghi nhận không sửa, không xoá).
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Thanh toán';

    protected static ?string $modelLabel = 'thanh toán';

    protected static ?string $pluralModelLabel = 'thanh toán';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedCreditCard;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('payments.view');
    }

    public function table(Table $table): Table
    {
        // Trên trang hóa đơn thì không cần cột số hóa đơn.
        return PaymentResource::paymentsTable($table, withInvoice: ! $this->getOwnerRecord() instanceof Invoice)
            ->emptyStateHeading('Chưa có thanh toán nào.')
            ->emptyStateDescription('Thanh toán được ghi nhận bằng nút "Ghi nhận thanh toán" trên trang hóa đơn.');
    }
}
