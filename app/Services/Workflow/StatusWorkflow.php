<?php

namespace App\Services\Workflow;

use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use BackedEnum;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Khung chung cho các quy trình đổi trạng thái (hợp đồng, chi phí,
 * mua hàng, hóa đơn...). Mỗi bước chuyển:
 *
 *   1. chạy trong một transaction và khoá dòng bản ghi;
 *   2. kiểm tra quyền của người thực hiện qua policy;
 *   3. kiểm tra bước chuyển có hợp lệ theo enum trạng thái;
 *   4. ghi trạng thái mới cùng các cột do $changes trả về.
 *
 * Giao diện chỉ gọi vào các lớp con của lớp này, không tự sửa cột `status`.
 */
abstract class StatusWorkflow
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @param  (Closure(TModel): array<string, mixed>)|null  $changes
     * @param  list<BackedEnum>  $from  Chỉ cho phép khi đang ở một trong các trạng thái này (rỗng = theo enum).
     * @return TModel
     */
    protected function transition(
        Model $record,
        User $by,
        string $ability,
        BackedEnum $target,
        ?Closure $changes = null,
        array $from = [],
    ): Model {
        // Nhật ký thao tác ghi bước chuyển này dưới tên người thực hiện ($by).
        return AuditLogger::transactionAs($by, function () use ($record, $by, $ability, $target, $changes, $from): Model {
            $record = $this->lock($record);

            $this->authorize($by, $ability, $record);

            if ($from !== [] && ! in_array($record->status, $from, true)) {
                $this->failTransition($record, $target);
            }

            if (! $record->status->canTransitionTo($target)) {
                $this->failTransition($record, $target);
            }

            $record->forceFill([...($changes ? $changes($record) : []), 'status' => $target])->save();

            return $record;
        });
    }

    /**
     * Đọc lại bản ghi và khoá dòng tới hết transaction.
     *
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @return TModel
     */
    protected function lock(Model $record): Model
    {
        $query = $record->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($record), true)) {
            $query->withTrashed();
        }

        return $query->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function authorize(User $by, string $ability, Model $record): void
    {
        if ($by->cannot($ability, $record)) {
            throw new AuthorizationException('Bạn không có quyền thực hiện thao tác này.');
        }
    }

    protected function failTransition(Model $record, BackedEnum $target): never
    {
        $name = $record->getAttribute('code') ?? ('#'.$record->getKey());

        throw new WorkflowException(
            "{$name} đang ở trạng thái \"{$record->status->getLabel()}\" nên không thể chuyển sang \"{$target->getLabel()}\"."
        );
    }

    protected function requireText(?string $text, string $message): string
    {
        $text = $this->clean($text);

        if ($text === null) {
            throw new WorkflowException($message);
        }

        return $text;
    }

    protected function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
