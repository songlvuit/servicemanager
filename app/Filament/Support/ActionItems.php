<?php

namespace App\Filament\Support;

use App\Enums\AcceptanceStatus;
use App\Enums\ExpenseStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Danh sách "Cần xử lý" trên dashboard (mục 36): mỗi dòng là một con số kèm
 * đường dẫn tới đúng danh sách đã lọc sẵn.
 *
 * Mỗi con số được đếm bằng CHÍNH truy vấn của màn hình danh sách tương ứng
 * (Resource::getEloquentQuery() + điều kiện của tab), nên số trên dashboard
 * luôn khớp với số dòng người dùng thấy sau khi bấm vào, và chỉ gồm những
 * chứng từ người đó được xem.
 */
class ActionItems
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const SUCCESS = 'success';

    /**
     * @return list<array{key: string, level: string, count: int, label: string, url: string}>
     */
    public static function for(User $user): array
    {
        $items = [];

        $add = function (string $key, string $level, int $count, string $label, string $url) use (&$items): void {
            if ($count > 0) {
                $items[] = compact('key', 'level', 'count', 'label', 'url');
            }
        };

        if ($user->can('invoices.view')) {
            $add(
                'invoices_overdue',
                self::DANGER,
                self::live(InvoiceResource::getEloquentQuery())->pastDue()->count(),
                'hóa đơn quá hạn thanh toán',
                InvoiceResource::getUrl('index', ['tab' => 'overdue']),
            );
        } elseif ($user->can('receivables.view')) {
            $add(
                'invoices_overdue',
                self::DANGER,
                self::live(ReceivableResource::getEloquentQuery())->pastDue()->count(),
                'hóa đơn quá hạn thanh toán',
                ReceivableResource::getUrl('index'),
            );
        }

        if ($user->can('tasks.view') && $user->can('internal_costs.view')) {
            $add(
                'tasks_over_budget',
                self::DANGER,
                self::live(TaskResource::getEloquentQuery())->overBudget()->count(),
                'công việc vượt ngân sách',
                TaskResource::getUrl('index', ['tab' => 'over_budget']),
            );
        }

        if ($user->can('tasks.view')) {
            $add(
                'tasks_overdue',
                self::DANGER,
                self::live(TaskResource::getEloquentQuery())->overdue()->count(),
                'công việc quá hạn',
                TaskResource::getUrl('index', ['tab' => 'overdue']),
            );
        }

        if ($user->can('expenses.view')) {
            $add(
                'expenses_awaiting_me',
                self::WARNING,
                self::live(ExpenseResource::getEloquentQuery())->awaitingApprovalBy($user)->count(),
                'đề nghị chi đang chờ bạn duyệt',
                ExpenseResource::getUrl('index', ['tab' => 'awaiting_me']),
            );
        }

        if ($user->can('purchases.view')) {
            $add(
                'purchase_requests_awaiting_me',
                self::WARNING,
                self::live(PurchaseRequestResource::getEloquentQuery())->awaitingApprovalBy($user)->count(),
                'yêu cầu mua hàng đang chờ bạn duyệt',
                PurchaseRequestResource::getUrl('index', ['tab' => 'awaiting_me']),
            );
        }

        if ($user->can('quotations.approve')) {
            $add(
                'quotations_pending',
                self::WARNING,
                self::live(QuotationResource::getEloquentQuery())->where('status', QuotationStatus::PendingApproval->value)->count(),
                'báo giá đang chờ duyệt',
                QuotationResource::getUrl('index', ['tab' => 'pending']),
            );
        }

        if ($user->can('acceptances.approve')) {
            $add(
                'acceptances_submitted',
                self::WARNING,
                self::live(AcceptanceResource::getEloquentQuery())->where('status', AcceptanceStatus::Submitted->value)->count(),
                'biên bản nghiệm thu đang chờ duyệt',
                AcceptanceResource::getUrl('index', ['filters' => ['status' => ['value' => AcceptanceStatus::Submitted->value]]]),
            );
        }

        if ($user->can('expenses.pay')) {
            $add(
                'expenses_to_pay',
                self::WARNING,
                self::live(ExpenseResource::getEloquentQuery())->where('status', ExpenseStatus::Approved->value)->count(),
                'đề nghị chi đã duyệt, chờ thanh toán',
                ExpenseResource::getUrl('index', ['tab' => 'approved']),
            );
        }

        if ($user->can('contracts.view')) {
            $days = (int) config('job.contract_expiry_warning_days', 30);

            $add(
                'contracts_expiring',
                self::WARNING,
                self::live(ContractResource::getEloquentQuery())->expiringWithin($days)->count(),
                "hợp đồng hết hạn trong {$days} ngày tới",
                ContractResource::getUrl('index', ['tab' => 'expiring']),
            );
        }

        if ($user->can('payments.view')) {
            $since = today()->subDays(6);

            $add(
                'payments_received',
                self::SUCCESS,
                self::live(PaymentResource::getEloquentQuery())->whereDate('payment_date', '>=', $since)->count(),
                'khoản thanh toán đã nhận trong 7 ngày qua',
                PaymentResource::getUrl('index', ['filters' => ['payment_date_range' => ['from' => $since->toDateString()]]]),
            );
        }

        return $items;
    }

    /**
     * Bỏ các bản ghi đã xoá mềm — màn hình danh sách mặc định cũng ẩn chúng.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function live(Builder $query): Builder
    {
        $model = $query->getModel();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->whereNull($model->getQualifiedDeletedAtColumn());
        }

        return $query;
    }
}
