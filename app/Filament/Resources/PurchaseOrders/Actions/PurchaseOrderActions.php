<?php

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Support\WorkflowAction;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Các nút của quy trình đơn mua hàng (mục 25).
 */
class PurchaseOrderActions
{
    public static function order(): Action
    {
        return static::step('order', 'Đặt hàng', PurchaseOrderStatus::Ordered, 'Đã đặt hàng.')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (PurchaseOrder $record): string => "Đặt hàng theo đơn {$record->code}?")
            ->modalDescription(fn (PurchaseOrder $record): string => 'Bạn có chắc chắn muốn đặt hàng trị giá '.Money::format($record->total).'? Giá trị trước thuế sẽ được tính vào chi phí cam kết của công việc và đơn không sửa được nữa.');
    }

    public static function receive(): Action
    {
        return static::step('receive', 'Đã nhận hàng', PurchaseOrderStatus::Received, 'Đã ghi nhận nhận hàng.')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('success')
            ->modalHeading(fn (PurchaseOrder $record): string => "Xác nhận đã nhận hàng của đơn {$record->code}?")
            ->modalDescription('Giá trị đơn hàng sẽ được ghi vào chi phí thực tế của công việc.');
    }

    public static function complete(): Action
    {
        return static::step('complete', 'Hoàn tất đơn', PurchaseOrderStatus::Completed, 'Đơn mua hàng đã hoàn tất.')
            ->icon(Heroicon::OutlinedFlag)
            ->color('info')
            ->modalHeading(fn (PurchaseOrder $record): string => "Hoàn tất đơn {$record->code}?")
            ->modalDescription('Xác nhận đã thanh toán cho nhà cung cấp và đóng đơn.');
    }

    public static function cancel(): Action
    {
        return static::step('cancel', 'Huỷ đơn', PurchaseOrderStatus::Cancelled, 'Đã huỷ đơn mua hàng.')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(fn (PurchaseOrder $record): string => "Huỷ đơn {$record->code}?")
            ->modalDescription('Đơn đã huỷ không dùng lại được; yêu cầu mua hàng liên quan trở lại trạng thái "Đã duyệt".');
    }

    private static function step(string $name, string $label, PurchaseOrderStatus $target, string $success): Action
    {
        return WorkflowAction::make($name, $label, $target)
            ->action(fn (PurchaseOrder $record) => WorkflowAction::run(
                $record,
                fn () => app(PurchaseOrderWorkflow::class)->{$name}($record, auth()->user()),
                $success,
            ));
    }
}
