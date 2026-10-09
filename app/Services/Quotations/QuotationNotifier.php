<?php

namespace App\Services\Quotations;

use App\Enums\SystemRole;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Thông báo trong ứng dụng (chuông trên thanh trên cùng) cho quy trình báo giá.
 */
class QuotationNotifier
{
    public function approvalRequested(Quotation $quotation, ?User $submittedBy = null): void
    {
        $recipients = $this->approversFor($quotation)
            ->reject(fn (User $user): bool => $submittedBy !== null && $user->is($submittedBy));

        if ($recipients->isEmpty()) {
            return;
        }

        $body = "{$quotation->code} — {$quotation->title} (".Money::format($quotation->total).')';

        if ($quotation->requires_director_approval) {
            $body .= '. Chiết khấu vượt ngưỡng: cần Giám đốc duyệt.';
        } elseif ($quotation->hasLowMargin()) {
            $body .= '. Biên lợi nhuận thấp.';
        }

        $this->base($quotation, 'Báo giá cần duyệt', $body)
            ->warning()
            ->sendToDatabase($recipients);
    }

    public function approved(Quotation $quotation): void
    {
        $this->notifyCreator(
            $quotation,
            $this->base($quotation, 'Báo giá đã được duyệt', "{$quotation->code} — {$quotation->title}")->success(),
        );
    }

    public function rejected(Quotation $quotation): void
    {
        $this->notifyCreator(
            $quotation,
            $this->base(
                $quotation,
                'Báo giá bị từ chối',
                "{$quotation->code} — {$quotation->title}. Lý do: {$quotation->rejection_reason}",
            )->danger(),
        );
    }

    /**
     * Những người được duyệt báo giá này. Khi chiết khấu vượt ngưỡng, chỉ
     * Giám đốc và Super Admin mới duyệt được.
     *
     * @return Collection<int, User>
     */
    public function approversFor(Quotation $quotation): Collection
    {
        $seniorRoles = [SystemRole::Director->value, SystemRole::SuperAdmin->value];

        $approvers = User::query()->where('is_active', true)->role($seniorRoles)->get();

        if (! $quotation->requires_director_approval) {
            $approvers = $approvers->merge(
                User::query()->where('is_active', true)->permission('quotations.approve')->get(),
            );
        }

        return $approvers
            ->unique(fn (User $user): int => $user->getKey())
            ->filter(fn (User $user): bool => $user->can('approve', $quotation))
            ->values();
    }

    private function notifyCreator(Quotation $quotation, Notification $notification): void
    {
        $creator = $quotation->creator;

        if ($creator !== null && $creator->is_active) {
            $notification->sendToDatabase($creator);
        }
    }

    private function base(Quotation $quotation, string $title, string $body): Notification
    {
        return Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-document-text')
            ->actions([
                Action::make('view')
                    ->label('Xem báo giá')
                    ->url(QuotationResource::getUrl('view', ['record' => $quotation])),
            ]);
    }
}
