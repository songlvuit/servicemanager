<?php

namespace App\Services\Contracts;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Exceptions\WorkflowException;
use App\Models\Contract;
use App\Models\User;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Các bước đổi trạng thái của hợp đồng (mục 15):
 * Nháp → Chờ ký → Đang hiệu lực → Đã hoàn thành, hoặc Đã huỷ.
 */
class ContractWorkflow extends StatusWorkflow
{
    /**
     * Nháp → Chờ ký.
     */
    public function sendForSignature(Contract $contract, User $by): Contract
    {
        return $this->transition($contract, $by, 'sendForSignature', ContractStatus::PendingSignature, function (Contract $contract): array {
            if (! Decimal::isPositive($contract->contract_value)) {
                throw new WorkflowException('Giá trị hợp đồng phải lớn hơn 0.');
            }

            return [];
        });
    }

    /**
     * Chờ ký → Đang hiệu lực (hai bên đã ký).
     */
    public function activate(Contract $contract, User $by, ?CarbonInterface $signDate = null): Contract
    {
        return $this->transition($contract, $by, 'activate', ContractStatus::Active, function (Contract $contract) use ($by, $signDate): array {
            // Khách hàng tiềm năng đã ký hợp đồng thành khách hàng đang hoạt động.
            $customer = $contract->customer;

            if ($customer !== null && $customer->status === CustomerStatus::Lead) {
                $customer->update(['status' => CustomerStatus::Active]);
            }

            return [
                'sign_date' => $signDate ?? $contract->sign_date ?? today(),
                'activated_by' => $by->getKey(),
                'activated_at' => now(),
            ];
        });
    }

    /**
     * Chờ ký → Nháp để chỉnh sửa nội dung.
     */
    public function returnToDraft(Contract $contract, User $by): Contract
    {
        return $this->transition($contract, $by, 'returnToDraft', ContractStatus::Draft);
    }

    /**
     * Đang hiệu lực → Đã hoàn thành. Mọi công việc của hợp đồng phải xong (hoặc huỷ) trước.
     */
    public function complete(Contract $contract, User $by): Contract
    {
        return $this->transition($contract, $by, 'complete', ContractStatus::Completed, function (Contract $contract): array {
            if ($this->hasOpenTasks($contract)) {
                throw new WorkflowException('Hợp đồng còn công việc chưa hoàn thành nên chưa thể kết thúc.');
            }

            return [];
        });
    }

    public function cancel(Contract $contract, User $by, string $reason): Contract
    {
        $reason = $this->requireText($reason, 'Vui lòng nhập lý do huỷ hợp đồng.');

        return $this->transition($contract, $by, 'cancel', ContractStatus::Cancelled, function (Contract $contract) use ($reason): array {
            if ($this->hasOpenTasks($contract)) {
                throw new WorkflowException('Hợp đồng còn công việc đang làm. Hãy hoàn thành hoặc huỷ các công việc trước.');
            }

            return ['cancel_reason' => $reason];
        });
    }

    private function hasOpenTasks(Contract $contract): bool
    {
        return $contract->tasks()->open()->exists();
    }
}
