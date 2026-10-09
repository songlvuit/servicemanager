<?php

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Workflow\StatusWorkflow;

/**
 * Các bước đổi trạng thái của dự án (mục 16).
 */
class ProjectWorkflow extends StatusWorkflow
{
    /**
     * Lập kế hoạch → Đang thực hiện.
     */
    public function start(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'start', ProjectStatus::Active, from: [ProjectStatus::Planning]);
    }

    public function hold(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'hold', ProjectStatus::OnHold);
    }

    public function resume(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'resume', ProjectStatus::Active, from: [ProjectStatus::OnHold]);
    }

    public function complete(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'complete', ProjectStatus::Completed);
    }

    /**
     * Mở lại dự án đã hoàn thành để ghi nhận thêm công việc / chi phí.
     */
    public function reopen(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'reopen', ProjectStatus::Active, from: [ProjectStatus::Completed]);
    }

    public function cancel(Project $project, User $by): Project
    {
        return $this->transition($project, $by, 'cancel', ProjectStatus::Cancelled);
    }
}
