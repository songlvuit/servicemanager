<?php

namespace App\Filament\Support;

use App\Exceptions\WorkflowException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Dựng các nút của quy trình (duyệt, từ chối, kích hoạt...) theo cùng một
 * khuôn: chỉ hiện khi trạng thái cho phép VÀ người dùng có quyền (policy),
 * luôn hỏi xác nhận (mục 66), lỗi nghiệp vụ hiện thành thông báo.
 */
class WorkflowAction
{
    /**
     * @param  string  $ability  Tên phương thức policy, cũng là tên của nút.
     * @param  BackedEnum  $target  Trạng thái đích — nút ẩn nếu enum không cho chuyển tới.
     * @param  list<BackedEnum>  $from  Chỉ hiện khi đang ở một trong các trạng thái này.
     */
    public static function make(string $ability, string $label, BackedEnum $target, array $from = []): Action
    {
        return Action::make($ability)
            ->label($label)
            ->requiresConfirmation()
            ->visible(function (Model $record) use ($ability, $target, $from): bool {
                if (method_exists($record, 'trashed') && $record->trashed()) {
                    return false;
                }

                if ($from !== [] && ! in_array($record->status, $from, true)) {
                    return false;
                }

                return $record->status->canTransitionTo($target)
                    && (auth()->user()?->can($ability, $record) ?? false);
            });
    }

    /**
     * Chạy một bước của quy trình và báo kết quả cho người dùng.
     *
     * @param  Closure(): mixed  $step
     */
    public static function run(Model $record, Closure $step, string $successMessage): void
    {
        try {
            $step();
        } catch (WorkflowException|AuthorizationException $exception) {
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
}
