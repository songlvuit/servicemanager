<?php

namespace App\Models\Concerns;

use App\Models\Task;
use App\Services\Tasks\TaskFinancials;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chứng từ gắn được vào một CÔNG VIỆC (đề nghị chi, yêu cầu / đơn mua hàng,
 * giờ công). Mỗi lần lưu hoặc xoá, chi phí của công việc được tính lại — kể
 * cả công việc cũ nếu chứng từ vừa được chuyển sang công việc khác. Chứng từ
 * không gắn công việc nào là chi phí chung của công ty.
 */
trait AffectsTaskCosts
{
    protected static function bootAffectsTaskCosts(): void
    {
        $recalculate = function (Model $model): void {
            $taskIds = array_unique(array_filter([
                $model->getAttribute('task_id'),
                $model->getOriginal('task_id'),
            ]));

            foreach (Task::query()->whereKey($taskIds)->get() as $task) {
                app(TaskFinancials::class)->recalculate($task);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);

        if (method_exists(static::class, 'restored')) {
            static::restored($recalculate);
        }
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
