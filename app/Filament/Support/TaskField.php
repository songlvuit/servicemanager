<?php

namespace App\Filament\Support;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ô "Công việc" trên các chứng từ tiền (đề nghị chi, yêu cầu / đơn mua hàng)
 * và trên lịch: gắn chứng từ vào một công việc để chi phí được tính cho công
 * việc đó.
 *
 * Chứng từ không gắn công việc nào là chi phí chung của công ty.
 */
class TaskField
{
    public static function select(string $name = 'task_id'): Select
    {
        return Select::make($name)
            ->label('Công việc')
            ->options(fn (Get $get): array => static::options($get($name)))
            ->default(fn (): ?int => static::requested()?->getKey())
            ->searchable()
            ->placeholder('Không gắn công việc')
            ->helperText('Chọn để khoản này được tính vào chi phí của công việc đó. Bỏ trống thì là chi phí chung của công ty.');
    }

    /**
     * Dòng "Công việc" trên trang chi tiết của chứng từ, bấm được để mở công việc.
     */
    public static function entry(): TextEntry
    {
        return TextEntry::make('task.name')
            ->label('Công việc')
            ->placeholder('Không gắn công việc')
            ->url(fn (Model $record): ?string => $record->task !== null && (auth()->user()?->can('view', $record->task) ?? false)
                ? TaskResource::getUrl('view', ['record' => $record->task])
                : null);
    }

    /**
     * Công việc chưa huỷ mà người dùng được gắn chứng từ vào; luôn kèm công việc đang chọn.
     *
     * @return array<int, string>
     */
    public static function options(mixed $selected = null): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return static::selectable($user)
            // Việc đã xong vẫn nhận chi phí (hoá đơn về sau); chỉ loại việc đã huỷ.
            ->where(function (Builder $query) use ($selected): void {
                $query->where('status', '!=', TaskStatus::Cancelled->value);

                if (filled($selected)) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'name', 'start_date'])
            ->mapWithKeys(fn (Task $task): array => [$task->getKey() => static::label($task)])
            ->all();
    }

    /**
     * Tên kèm ngày, vì nhiều công việc trùng tên: "Livestream hội thảo (06/10/2026)".
     */
    public static function label(Task $task): string
    {
        return $task->start_date !== null
            ? $task->name.' ('.$task->start_date->format('d/m/Y').')'
            : $task->name;
    }

    /**
     * Công việc truyền qua đường dẫn (?task=ID) khi mở form từ trang công việc.
     */
    public static function requested(): ?Task
    {
        $id = request()->integer('task');
        $user = auth()->user();

        if ($id <= 0 || $user === null) {
            return null;
        }

        return static::selectable($user)->find($id);
    }

    /**
     * Công việc người dùng được gắn chứng từ vào: việc của mình (được giao hoặc
     * có trong ê-kíp); người làm việc với chi phí nội bộ — kế toán, mua hàng,
     * quản lý — thì chọn được mọi công việc.
     *
     * @return Builder<Task>
     */
    private static function selectable(User $user): Builder
    {
        return $user->can('internal_costs.view')
            ? Task::query()
            : Task::query()->visibleTo($user);
    }
}
