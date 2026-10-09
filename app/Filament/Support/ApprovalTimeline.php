<?php

namespace App\Filament\Support;

use App\Enums\SystemRole;
use App\Models\ApprovalStep;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Khối "Quy trình duyệt" trên trang chi tiết của chứng từ có duyệt (đề nghị
 * chi, yêu cầu mua hàng): các bước, vai trò phải duyệt, ai đã duyệt, khi nào.
 */
class ApprovalTimeline
{
    public static function section(): Section
    {
        $text = fn (string $name): TextEntry => TextEntry::make($name)->hiddenLabel()->placeholder('—');

        return Section::make('Quy trình duyệt')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->description('Các bước sinh ra từ cấu hình Hệ thống → Quy trình duyệt, theo số tiền của chứng từ.')
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('approval_steps')
                    ->hiddenLabel()
                    ->state(fn (Model $record): array => static::steps($record))
                    ->table([
                        TableColumn::make('Bước'),
                        TableColumn::make('Vai trò duyệt'),
                        TableColumn::make('Kết quả'),
                        TableColumn::make('Người duyệt'),
                        TableColumn::make('Thời gian'),
                        TableColumn::make('Ghi chú'),
                    ])
                    ->schema([
                        $text('name'),
                        $text('role'),
                        TextEntry::make('status')->hiddenLabel()->badge(),
                        $text('approver'),
                        $text('time'),
                        $text('comment'),
                    ])
                    ->placeholder('Chưa gửi duyệt.'),
            ]);
    }

    public static function roleLabel(?string $role): string
    {
        return SystemRole::tryFrom((string) $role)?->label() ?? (string) $role;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function steps(Model $record): array
    {
        $request = $record->currentApproval()->with('steps.approver')->first();

        if ($request === null) {
            return [];
        }

        return $request->steps
            ->map(fn (ApprovalStep $step): array => [
                'name' => $step->name,
                'role' => static::roleLabel($step->approver_role),
                'status' => $step->status,
                'approver' => $step->approver?->name,
                'time' => $step->approved_at?->format('d/m/Y H:i'),
                'comment' => $step->comment,
            ])
            ->values()
            ->all();
    }
}
