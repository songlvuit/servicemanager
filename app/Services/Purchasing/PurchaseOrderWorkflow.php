<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Quy trình đơn mua hàng (mục 25): Nháp → Đã đặt hàng → Đã nhận hàng → Hoàn tất.
 *
 * Chi phí công việc: đã đặt hàng = chi phí cam kết, đã nhận hàng = chi phí thực tế.
 * Yêu cầu mua hàng gắn với đơn được chuyển trạng thái theo đơn.
 */
class PurchaseOrderWorkflow extends StatusWorkflow
{
    /**
     * Tính lại subtotal, thuế, tổng từ các dòng hàng. Chỉ áp dụng cho đơn nháp.
     */
    public function recalculate(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = $this->lock($order);

            if (! $order->isEditable()) {
                throw new WorkflowException("Đơn mua hàng {$order->code} không còn là bản nháp nên không thể tính lại.");
            }

            return $this->applyTotals($order);
        });
    }

    /**
     * Nháp → Đã đặt hàng (gửi đơn cho nhà cung cấp).
     */
    public function order(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return $this->transition($order, $by, 'order', PurchaseOrderStatus::Ordered, function (PurchaseOrder $order): array {
            if ($order->items()->doesntExist()) {
                throw new WorkflowException('Đơn mua hàng chưa có dòng hàng nào.');
            }

            $order = $this->applyTotals($order);

            if (! Decimal::isPositive($order->total)) {
                throw new WorkflowException('Tổng giá trị đơn mua hàng phải lớn hơn 0.');
            }

            if ($order->task?->isCancelled()) {
                throw new WorkflowException('Công việc đã huỷ nên không đặt thêm hàng được.');
            }

            $request = $order->purchaseRequest;

            if ($request !== null) {
                if ($request->status !== PurchaseRequestStatus::Approved) {
                    throw new WorkflowException("Yêu cầu mua hàng {$request->code} chưa được duyệt hoặc đã được đặt hàng.");
                }

                $request->forceFill(['status' => PurchaseRequestStatus::Ordered])->save();
            }

            return ['ordered_at' => now()];
        });
    }

    /**
     * Đã đặt hàng → Đã nhận hàng: ghi nhận chi phí thực tế của công việc.
     */
    public function receive(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return $this->transition($order, $by, 'receive', PurchaseOrderStatus::Received, function (PurchaseOrder $order): array {
            $this->moveRequest($order, PurchaseRequestStatus::Ordered, PurchaseRequestStatus::Received);

            return ['received_at' => now()];
        });
    }

    /**
     * Đã nhận hàng → Hoàn tất (đã thanh toán cho nhà cung cấp, đóng đơn).
     */
    public function complete(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return $this->transition($order, $by, 'complete', PurchaseOrderStatus::Completed, function (PurchaseOrder $order): array {
            $this->moveRequest($order, PurchaseRequestStatus::Received, PurchaseRequestStatus::Completed);

            return ['completed_at' => now()];
        });
    }

    /**
     * Huỷ đơn nháp hoặc đơn đã đặt nhưng chưa nhận hàng. Yêu cầu mua hàng trở
     * lại "Đã duyệt" để có thể lập đơn khác.
     */
    public function cancel(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return $this->transition($order, $by, 'cancel', PurchaseOrderStatus::Cancelled, function (PurchaseOrder $order): array {
            $this->moveRequest($order, PurchaseRequestStatus::Ordered, PurchaseRequestStatus::Approved);

            return [];
        });
    }

    private function moveRequest(PurchaseOrder $order, PurchaseRequestStatus $from, PurchaseRequestStatus $to): void
    {
        $request = $order->purchaseRequest;

        if ($request !== null && $request->status === $from) {
            $request->forceFill(['status' => $to])->save();
        }
    }

    private function applyTotals(PurchaseOrder $order): PurchaseOrder
    {
        $subtotal = '0';
        $tax = '0';

        $order->items()->get()->each(function (PurchaseOrderItem $item) use (&$subtotal, &$tax): void {
            $subtotal = Decimal::add($subtotal, $item->subtotal);
            $tax = Decimal::add($tax, $item->taxAmount());
        });

        $order->forceFill([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => Decimal::add($subtotal, $tax),
        ])->save();

        return $order;
    }
}
