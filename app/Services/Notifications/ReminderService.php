<?php

namespace App\Services\Notifications;

use App\Enums\SystemRole;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\ReminderLog;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\Scheduling\ScheduleNotifier;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Nhắc việc theo ngày (mục 38), chạy mỗi sáng bằng lệnh `reminders:send`:
 *
 *   - hợp đồng sắp hết hạn        → người lập hợp đồng + Giám đốc (ai còn quyền xem hợp đồng)
 *   - hóa đơn sắp tới hạn         → người lập hóa đơn + Kế toán
 *   - hóa đơn quá hạn             → người lập hóa đơn + Kế toán + Giám đốc
 *   - lịch làm việc của ngày mai    → từng người trong ê-kíp
 *
 * Mỗi sự kiện chỉ được nhắc MỘT lần (bảng reminder_logs). Khi ngày hết hạn /
 * đến hạn của chứng từ thay đổi thì được nhắc lại cho ngày mới. Số ngày nhắc
 * trước lấy từ Hệ thống → Cấu hình.
 */
class ReminderService
{
    public function __construct(
        private readonly ScheduleNotifier $schedules,
    ) {}

    /**
     * @return array{contracts: int, invoices_due: int, invoices_overdue: int, schedules: int}
     */
    public function send(): array
    {
        return [
            'contracts' => $this->contractsExpiring(),
            'invoices_due' => $this->invoicesDueSoon(),
            'invoices_overdue' => $this->invoicesOverdue(),
            'schedules' => $this->schedulesTomorrow(),
        ];
    }

    /**
     * Nhắc từng người trong ê-kíp về các lịch bắt đầu NGÀY MAI (không nhắc
     * lịch nghỉ và lịch đã huỷ). Đổi ngày của lịch thì được nhắc lại.
     */
    public function schedulesTomorrow(): int
    {
        $tomorrow = today()->addDay();
        $sent = 0;

        ScheduleEntry::query()
            ->with(['crew', 'type'])
            ->occupying()
            ->whereDate('starts_at', $tomorrow->toDateString())
            ->whereHas('type', fn (Builder $query) => $query->where('is_leave', false))
            ->orderBy('starts_at')
            ->each(function (ScheduleEntry $entry) use (&$sent, $tomorrow): void {
                foreach ($entry->crew as $user) {
                    if (! $user->is_active || ! ReminderLog::claim("schedule:{$entry->getKey()}:{$user->getKey()}:{$tomorrow->toDateString()}")) {
                        continue;
                    }

                    $this->schedules->reminder($entry, $user);
                    $sent++;
                }
            });

        return $sent;
    }

    public function contractsExpiring(): int
    {
        $days = (int) config('job.contract_expiry_warning_days', 30);
        $sent = 0;

        Contract::query()
            ->with('customer')
            ->expiringWithin($days)
            ->whereDate('end_date', '>=', today())
            ->each(function (Contract $contract) use (&$sent): void {
                if (! ReminderLog::claim("contract-expiring:{$contract->getKey()}:{$contract->end_date->toDateString()}")) {
                    return;
                }

                $left = (int) today()->diffInDays($contract->end_date, false);

                $this->notify(
                    $this->recipients('contracts.view', $contract->created_by, SystemRole::Director),
                    Notification::make()
                        ->title('Hợp đồng sắp hết hạn')
                        ->body("{$contract->code} — {$contract->customer?->name}: hết hạn ngày {$contract->end_date->format('d/m/Y')} (".$this->when($left).').')
                        ->icon('heroicon-o-document-check')
                        ->warning()
                        ->actions([$this->link('Xem hợp đồng', ContractResource::getUrl('view', ['record' => $contract]))]),
                );

                $sent++;
            });

        return $sent;
    }

    public function invoicesDueSoon(): int
    {
        $days = (int) config('billing.due_reminder_days', 7);
        $sent = 0;

        Invoice::query()
            ->with('customer')
            ->open()
            ->whereDate('due_date', '>=', today())
            ->whereDate('due_date', '<=', today()->addDays($days))
            ->each(function (Invoice $invoice) use (&$sent): void {
                if (! ReminderLog::claim("invoice-due:{$invoice->getKey()}:{$invoice->due_date->toDateString()}")) {
                    return;
                }

                $left = (int) today()->diffInDays($invoice->due_date, false);

                $this->notify(
                    $this->recipients('invoices.view', $invoice->created_by, SystemRole::Accountant),
                    Notification::make()
                        ->title('Hóa đơn sắp tới hạn thanh toán')
                        ->body("{$invoice->code} — {$invoice->customer?->name}: còn phải thu ".Money::format($invoice->remaining_amount)
                            .", hạn {$invoice->due_date->format('d/m/Y')} (".$this->when($left).').')
                        ->icon('heroicon-o-receipt-percent')
                        ->warning()
                        ->actions([$this->link('Xem hóa đơn', InvoiceResource::getUrl('view', ['record' => $invoice]))]),
                );

                $sent++;
            });

        return $sent;
    }

    public function invoicesOverdue(): int
    {
        $sent = 0;

        Invoice::query()
            ->with('customer')
            ->pastDue()
            ->each(function (Invoice $invoice) use (&$sent): void {
                if (! ReminderLog::claim("invoice-overdue:{$invoice->getKey()}:{$invoice->due_date->toDateString()}")) {
                    return;
                }

                $this->notify(
                    $this->recipients('invoices.view', $invoice->created_by, SystemRole::Accountant, SystemRole::Director),
                    Notification::make()
                        ->title('Hóa đơn quá hạn thanh toán')
                        ->body("{$invoice->code} — {$invoice->customer?->name}: còn phải thu ".Money::format($invoice->remaining_amount)
                            .", đã quá hạn {$invoice->daysOverdue()} ngày (hạn {$invoice->due_date->format('d/m/Y')}).")
                        ->icon('heroicon-o-receipt-percent')
                        ->danger()
                        ->actions([$this->link('Xem hóa đơn', InvoiceResource::getUrl('view', ['record' => $invoice]))]),
                );

                $sent++;
            });

        return $sent;
    }

    /**
     * Người lập chứng từ và mọi người đang hoạt động giữ một trong các vai trò
     * — nhưng chỉ những ai CÒN quyền xem loại chứng từ đó (người lập có thể đã
     * đổi vai trò; hợp đồng, hóa đơn không được lộ qua thông báo).
     *
     * @return Collection<int, User>
     */
    private function recipients(string $permission, ?int $creatorId, SystemRole ...$roles): Collection
    {
        $roleNames = array_map(fn (SystemRole $role): string => $role->value, $roles);

        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($creatorId, $roleNames): void {
                $query
                    ->whereKey($creatorId ?? 0)
                    ->orWhereHas('roles', fn (Builder $query) => $query->whereIn('name', $roleNames));
            })
            ->get()
            ->filter(fn (User $user): bool => $user->can($permission))
            ->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(Collection $recipients, Notification $notification): void
    {
        if ($recipients->isNotEmpty()) {
            $notification->sendToDatabase($recipients);
        }
    }

    private function link(string $label, string $url): Action
    {
        return Action::make('view')->label($label)->url($url);
    }

    private function when(int $daysLeft): string
    {
        return $daysLeft <= 0 ? 'hôm nay' : "còn {$daysLeft} ngày";
    }
}
