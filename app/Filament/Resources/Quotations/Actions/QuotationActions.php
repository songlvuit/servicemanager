<?php

namespace App\Filament\Resources\Quotations\Actions;

use App\Enums\QuotationStatus;
use App\Exceptions\QuotationWorkflowException;
use App\Models\Quotation;
use App\Services\Quotations\QuotationWorkflow;
use App\Support\Decimal;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Các nút của quy trình báo giá (mục 14). Mỗi nút:
 *  - chỉ hiện khi trạng thái cho phép VÀ người dùng có quyền (policy);
 *  - luôn hỏi xác nhận (mục 66);
 *  - gọi QuotationWorkflow — nơi kiểm tra lại quyền và trạng thái lần nữa.
 */
class QuotationActions
{
    public static function submit(): Action
    {
        return static::make('submit', 'Gửi duyệt', QuotationStatus::PendingApproval)
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (Quotation $record): string => "Gửi duyệt báo giá {$record->code}?")
            ->modalDescription(fn (Quotation $record): string => static::describe(
                $record,
                'Sau khi gửi duyệt, báo giá không sửa được nữa cho tới khi có kết quả duyệt.',
            ))
            ->action(fn (Quotation $record) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->submit($record, auth()->user()),
                'Đã gửi duyệt báo giá.',
            ));
    }

    public static function approve(): Action
    {
        return static::make('approve', 'Duyệt', QuotationStatus::Approved)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Quotation $record): string => "Duyệt báo giá {$record->code}?")
            ->modalDescription(fn (Quotation $record): string => static::describe(
                $record,
                'Bạn có chắc chắn muốn duyệt báo giá trị giá '.Money::format($record->total).'?',
            ))
            ->schema([
                Textarea::make('note')
                    ->label('Ghi chú (không bắt buộc)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(fn (Quotation $record, array $data) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->approve($record, auth()->user(), $data['note'] ?? null),
                'Đã duyệt báo giá.',
            ));
    }

    public static function reject(): Action
    {
        return static::make('reject', 'Từ chối', QuotationStatus::Rejected, from: QuotationStatus::PendingApproval)
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (Quotation $record): string => "Từ chối báo giá {$record->code}?")
            ->modalDescription('Người lập sẽ nhận được lý do và có thể soạn lại để gửi duyệt lần nữa.')
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do từ chối')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Quotation $record, array $data) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->reject($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã từ chối báo giá.',
            ));
    }

    public static function markSent(): Action
    {
        return static::make('markSent', 'Đã gửi khách', QuotationStatus::Sent)
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('info')
            ->modalHeading(fn (Quotation $record): string => "Đánh dấu đã gửi báo giá {$record->code} cho khách?")
            ->modalDescription('Dùng khi bạn đã gửi báo giá này cho khách hàng.')
            ->action(fn (Quotation $record) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->markSent($record, auth()->user()),
                'Đã ghi nhận gửi báo giá cho khách.',
            ));
    }

    public static function markAccepted(): Action
    {
        return static::make('markAccepted', 'Khách chấp nhận', QuotationStatus::Accepted)
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->modalHeading(fn (Quotation $record): string => "Khách hàng chấp nhận báo giá {$record->code}?")
            ->modalDescription('Cơ hội liên quan sẽ chuyển sang "Thắng" và khách hàng tiềm năng trở thành khách hàng đang hoạt động.')
            ->action(fn (Quotation $record) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->markAccepted($record, auth()->user()),
                'Đã ghi nhận khách hàng chấp nhận báo giá.',
            ));
    }

    public static function markDeclined(): Action
    {
        return static::make('markDeclined', 'Khách từ chối', QuotationStatus::Rejected, from: QuotationStatus::Sent)
            ->icon(Heroicon::OutlinedHandThumbDown)
            ->color('danger')
            ->modalHeading(fn (Quotation $record): string => "Khách hàng từ chối báo giá {$record->code}?")
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do khách hàng từ chối')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (Quotation $record, array $data) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->markDeclined($record, auth()->user(), (string) ($data['reason'] ?? '')),
                'Đã ghi nhận khách hàng từ chối báo giá.',
            ));
    }

    public static function revise(): Action
    {
        return static::make('revise', 'Soạn lại', QuotationStatus::Draft)
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(fn (Quotation $record): string => "Soạn lại báo giá {$record->code}?")
            ->modalDescription('Báo giá trở về trạng thái Nháp để chỉnh sửa và gửi duyệt lại.')
            ->action(fn (Quotation $record) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->revise($record, auth()->user()),
                'Báo giá đã trở về bản nháp.',
            ));
    }

    public static function cancel(): Action
    {
        return static::make('cancel', 'Huỷ báo giá', QuotationStatus::Cancelled)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(fn (Quotation $record): string => "Huỷ báo giá {$record->code}?")
            ->modalDescription('Báo giá đã huỷ không dùng lại được. Thao tác này không hoàn tác được.')
            ->schema([
                Textarea::make('reason')
                    ->label('Lý do huỷ (không bắt buộc)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(fn (Quotation $record, array $data) => static::run(
                $record,
                fn (QuotationWorkflow $workflow) => $workflow->cancel($record, auth()->user(), $data['reason'] ?? null),
                'Đã huỷ báo giá.',
            ));
    }

    /**
     * Khung chung: nhãn, xác nhận và điều kiện hiển thị theo trạng thái + policy.
     */
    private static function make(string $name, string $label, QuotationStatus $target, ?QuotationStatus $from = null): Action
    {
        return Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->visible(function (Quotation $record) use ($name, $target, $from): bool {
                if ($record->trashed()) {
                    return false;
                }

                if ($from !== null && $record->status !== $from) {
                    return false;
                }

                return $record->status->canTransitionTo($target)
                    && (auth()->user()?->can($name, $record) ?? false);
            });
    }

    /**
     * Chạy một bước của quy trình và báo kết quả; lỗi nghiệp vụ được hiển thị
     * cho người dùng thay vì làm hỏng trang.
     *
     * @param  Closure(QuotationWorkflow): mixed  $step
     */
    private static function run(Quotation $record, Closure $step, string $successMessage): void
    {
        try {
            $step(app(QuotationWorkflow::class));
        } catch (QuotationWorkflowException|AuthorizationException $exception) {
            Notification::make()
                ->title('Không thực hiện được')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $record->refresh();

            return;
        }

        // Nạp lại để trang hiển thị trạng thái mới.
        $record->refresh();

        Notification::make()
            ->title($successMessage)
            ->success()
            ->send();
    }

    /**
     * Dòng mô tả trong hộp xác nhận, kèm cảnh báo biên lợi nhuận / chiết khấu.
     */
    private static function describe(Quotation $record, string $message): string
    {
        $warnings = [];

        if (Decimal::isPositive($record->subtotal) && $record->hasLowMargin()) {
            $warnings[] = 'Biên lợi nhuận thấp.';
        }

        if ($record->exceedsDirectorDiscountThreshold()) {
            $warnings[] = 'Chiết khấu '.Money::percent($record->discountPercent()).' vượt ngưỡng: cần Giám đốc duyệt.';
        }

        return trim($message.' '.implode(' ', $warnings));
    }
}
