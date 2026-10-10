<?php

namespace App\Services\Expenses;

use App\Enums\ApprovalDocumentType;
use App\Enums\ApprovalStepStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\User;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Approvals\ApprovalService;
use App\Services\Workflow\StatusWorkflow;
use App\Support\Audit\AuditLogger;
use App\Support\Decimal;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Quy trình đề nghị chi (mục 20–21, Rule 2 và 9):
 *
 *   Nháp → [các bước duyệt theo approval_rules] → Đã duyệt → Đã thanh toán → Hoàn tất
 *
 * Trong lúc duyệt, trạng thái thể hiện bước đang chờ: Chờ PM duyệt, Kế toán
 * kiểm tra, Chờ Giám đốc duyệt. Bị từ chối thì soạn lại và gửi duyệt lại.
 */
class ExpenseWorkflow extends StatusWorkflow
{
    private const ICON = 'heroicon-o-banknotes';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalNotifier $notifier,
    ) {}

    /**
     * Rule 9: mọi đề nghị chi đều phải qua quy trình duyệt.
     */
    public function submit(Expense $expense, User $by): Expense
    {
        [$expense, $request] = AuditLogger::transactionAs($by, function () use ($expense, $by): array {
            $expense = $this->lock($expense);

            $this->authorize($by, 'submit', $expense);
            $this->guard($expense, ExpenseStatus::Submitted, $expense->status === ExpenseStatus::Draft);

            if (! Decimal::isPositive($expense->total_amount)) {
                throw new WorkflowException('Số tiền đề nghị chi phải lớn hơn 0.');
            }

            if ($expense->task?->isCancelled()) {
                throw new WorkflowException('Công việc đã huỷ nên không nhận thêm đề nghị chi.');
            }

            $request = $this->approvals->start(
                $expense,
                ApprovalDocumentType::Expense,
                $expense->employee ?? $by,
                $expense->total_amount,
            );

            $expense->forceFill([
                'status' => $this->statusFor($request),
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ])->save();

            return [$expense, $request];
        });

        $this->notifyApprovers($expense, $request);

        return $expense;
    }

    /**
     * Duyệt bước hiện tại; hết bước thì đề nghị chi được duyệt.
     */
    public function approve(Expense $expense, User $by, ?string $comment = null): Expense
    {
        [$expense, $request] = AuditLogger::transactionAs($by, function () use ($expense, $by, $comment): array {
            $expense = $this->lock($expense);

            $this->authorize($by, 'approve', $expense);
            $this->guard($expense, ExpenseStatus::Approved, $expense->status->isInApproval());

            $request = $this->approvals->approve($this->currentRequest($expense), $by, $comment);

            $expense->forceFill($request->isPending()
                ? ['status' => $this->statusFor($request)]
                : ['status' => ExpenseStatus::Approved, 'approved_by' => $by->getKey(), 'approved_at' => now()]
            )->save();

            return [$expense, $request];
        });

        if ($request->isPending()) {
            $this->notifyApprovers($expense, $request);
        } else {
            $this->notifier->result(
                $expense->employee,
                'Đề nghị chi đã được duyệt',
                $this->summary($expense),
                $this->url($expense),
                self::ICON,
                success: true,
            );
        }

        return $expense;
    }

    public function reject(Expense $expense, User $by, string $reason): Expense
    {
        $reason = $this->requireText($reason, 'Vui lòng nhập lý do từ chối.');

        $expense = AuditLogger::transactionAs($by, function () use ($expense, $by, $reason): Expense {
            $expense = $this->lock($expense);

            $this->authorize($by, 'reject', $expense);
            $this->guard($expense, ExpenseStatus::Rejected, $expense->status->isInApproval());

            $this->approvals->reject($this->currentRequest($expense), $by, $reason);

            $expense->forceFill([
                'status' => ExpenseStatus::Rejected,
                'rejection_reason' => $reason,
            ])->save();

            return $expense;
        });

        $this->notifier->result(
            $expense->employee,
            'Đề nghị chi bị từ chối',
            $this->summary($expense).". Lý do: {$reason}",
            $this->url($expense),
            self::ICON,
            success: false,
        );

        return $expense;
    }

    /**
     * Bị từ chối → Nháp để chỉnh sửa và gửi duyệt lại.
     */
    public function revise(Expense $expense, User $by): Expense
    {
        return $this->transition($expense, $by, 'revise', ExpenseStatus::Draft, fn (): array => [
            'submitted_at' => null,
        ]);
    }

    /**
     * Rule 2: chỉ thanh toán được khoản chi đã duyệt.
     */
    public function pay(
        Expense $expense,
        User $by,
        PaymentMethod $method,
        ?CarbonInterface $paidAt = null,
        ?string $reference = null,
    ): Expense {
        return AuditLogger::transactionAs($by, function () use ($expense, $by, $method, $paidAt, $reference): Expense {
            $expense = $this->lock($expense);

            $this->authorize($by, 'pay', $expense);

            if ($expense->status !== ExpenseStatus::Approved) {
                throw new WorkflowException(
                    "Chỉ thanh toán được khoản chi đã duyệt. {$expense->code} đang ở trạng thái \"{$expense->status->getLabel()}\"."
                );
            }

            $expense->forceFill([
                'status' => ExpenseStatus::Paid,
                'payment_method' => $method,
                'paid_by' => $by->getKey(),
                'paid_at' => $paidAt ?? now(),
                'payment_reference' => $this->clean($reference),
            ])->save();

            return $expense;
        });
    }

    /**
     * Đã thanh toán → Hoàn tất (chứng từ đã đủ, đóng hồ sơ).
     */
    public function complete(Expense $expense, User $by): Expense
    {
        return $this->transition($expense, $by, 'complete', ExpenseStatus::Completed, fn (): array => [
            'completed_at' => now(),
        ]);
    }

    private function guard(Expense $expense, ExpenseStatus $target, bool $allowed): void
    {
        if (! $allowed || ! $expense->status->canTransitionTo($target)) {
            $this->failTransition($expense, $target);
        }
    }

    private function currentRequest(Expense $expense): ApprovalRequest
    {
        return $expense->currentApproval()->first()
            ?? throw new WorkflowException("{$expense->code} chưa được gửi duyệt.");
    }

    /**
     * Trạng thái hiển thị ứng với bước đang chờ của lần duyệt.
     */
    private function statusFor(ApprovalRequest $request): ExpenseStatus
    {
        $step = $request->pendingStep();

        if ($step === null) {
            return ExpenseStatus::Approved;
        }

        $isFirstStep = $request->steps()
            ->where('status', ApprovalStepStatus::Approved->value)
            ->doesntExist();

        return ExpenseStatus::awaiting($step->approver_role, $isFirstStep);
    }

    private function notifyApprovers(Expense $expense, ApprovalRequest $request): void
    {
        $this->notifier->awaiting(
            $request,
            'Đề nghị chi cần duyệt',
            $this->summary($expense),
            $this->url($expense),
            self::ICON,
        );
    }

    private function summary(Expense $expense): string
    {
        return "{$expense->code} — {$expense->description} (".Money::format($expense->total_amount).')';
    }

    private function url(Expense $expense): string
    {
        return ExpenseResource::getUrl('view', ['record' => $expense]);
    }
}
