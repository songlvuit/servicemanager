<?php

namespace Tests\Concerns;

use App\Models\Contract;
use App\Models\Task;

/**
 * Dựng nhanh hợp đồng đang hiệu lực và công việc gắn hợp đồng cho các bài test.
 */
trait BuildsJobs
{
    /**
     * Hợp đồng đang hiệu lực, giá trị 100.000.000 (mặc định của factory).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeActiveContract(array $attributes = []): Contract
    {
        return Contract::factory()->active()->create($attributes)->refresh();
    }

    /**
     * Công việc gắn với một hợp đồng đang hiệu lực (tạo mới nếu không truyền).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeJob(array $attributes = [], ?Contract $contract = null): Task
    {
        return Task::factory()->create([
            'contract_id' => ($contract ?? $this->makeActiveContract())->getKey(),
            ...$attributes,
        ])->refresh();
    }
}
