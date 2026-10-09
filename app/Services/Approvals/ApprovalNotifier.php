<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thông báo trong ứng dụng (chuông trên thanh trên cùng) cho các quy trình duyệt.
 */
class ApprovalNotifier
{
    /**
     * Báo cho những người giữ vai trò của bước đang chờ (trừ người đề nghị).
     */
    public function awaiting(ApprovalRequest $request, string $title, string $body, string $url, string $icon): void
    {
        if (! $request->isPending() || blank($request->current_role)) {
            return;
        }

        // Dùng whereHas thay cho scope role() để vai trò đã bị xoá không gây lỗi.
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where('name', $request->current_role))
            ->whereKeyNot($request->requester_id ?? 0)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->make($title, $body, $url, $icon)
            ->warning()
            ->sendToDatabase($recipients);
    }

    /**
     * Báo kết quả cho một người (thường là người đề nghị).
     */
    public function result(?User $user, string $title, string $body, string $url, string $icon, bool $success): void
    {
        if ($user === null || ! $user->is_active) {
            return;
        }

        $notification = $this->make($title, $body, $url, $icon);

        ($success ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }

    private function make(string $title, string $body, string $url, string $icon): Notification
    {
        return Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->actions([
                Action::make('view')
                    ->label('Xem chi tiết')
                    ->url($url),
            ]);
    }
}
