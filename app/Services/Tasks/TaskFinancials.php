<?php

namespace App\Services\Tasks;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\ScheduleEntry;
use App\Models\Task;
use App\Models\Timesheet;
use App\Services\Projects\ProjectCostCollector;
use App\Services\Projects\ProjectFinancials;
use Illuminate\Support\Facades\DB;

/**
 * Tiền của MỘT công việc: ngân sách do người quản lý nhập, còn chi phí được
 * tính từ các chứng từ gắn vào công việc (đề nghị chi, mua hàng, giờ công) theo
 * đúng quy tắc của chi phí dự án — xem ProjectCostCollector.
 *
 *   committed_cost = Σ chi phí đã cam kết (gồm cả phần đã chi)
 *   actual_cost    = Σ chi phí thực tế
 *
 * Gọi recalculate() mỗi khi một chứng từ của công việc thay đổi.
 */
class TaskFinancials
{
    public function __construct(
        private readonly ProjectCostCollector $costs,
        private readonly ProjectFinancials $projects,
    ) {}

    public function recalculate(Task $task): Task
    {
        $cost = $this->costs->forTask($task);

        // Ghi thẳng bằng query để không kích hoạt lại các sự kiện của công việc.
        Task::query()->whereKey($task->getKey())->update([
            'committed_cost' => $cost['committed'],
            'actual_cost' => $cost['actual'],
        ]);

        $task->setAttribute('committed_cost', $cost['committed']);
        $task->setAttribute('actual_cost', $cost['actual']);
        $task->syncOriginalAttributes(['committed_cost', 'actual_cost']);

        return $task;
    }

    /**
     * Chuyển công việc sang dự án khác (hoặc về "không gắn dự án"). Chứng từ và
     * lịch của công việc luôn theo dự án của công việc, nên được chuyển cùng;
     * chi phí của dự án cũ và dự án mới được tính lại.
     */
    public function moveToProject(Task $task, ?int $projectId): Task
    {
        $from = $task->project_id;

        if ($from === $projectId) {
            return $task;
        }

        return DB::transaction(function () use ($task, $from, $projectId): Task {
            // Công việc con (nếu có) đi cùng công việc cha.
            $taskIds = [$task->getKey(), ...$task->children()->pluck('id')->all()];

            Task::query()->whereKey($taskIds)->update(['project_id' => $projectId]);

            foreach ([Expense::class, PurchaseRequest::class, PurchaseOrder::class, Timesheet::class] as $model) {
                $model::query()->whereIn('task_id', $taskIds)->update(['project_id' => $projectId]);
            }

            $customerId = $projectId !== null ? Project::query()->withTrashed()->whereKey($projectId)->value('customer_id') : null;

            ScheduleEntry::query()->whereIn('task_id', $taskIds)->update([
                'project_id' => $projectId,
                // Gắn vào dự án thì khách hàng theo dự án; gỡ dự án thì giữ khách hàng đang có.
                ...($customerId !== null && Customer::query()->withTrashed()->whereKey($customerId)->exists() ? ['customer_id' => $customerId] : []),
            ]);

            $task->setAttribute('project_id', $projectId);
            $task->syncOriginalAttribute('project_id');
            $task->unsetRelation('project');

            foreach (Project::query()->withTrashed()->whereKey(array_filter([$from, $projectId]))->get() as $project) {
                $this->projects->recalculate($project);
                $this->projects->recalculateProgress($project);
            }

            return $task;
        });
    }
}
