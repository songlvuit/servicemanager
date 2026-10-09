<?php

namespace Tests\Concerns;

use App\Models\Contract;
use App\Models\Project;

/**
 * Dựng nhanh hợp đồng đang hiệu lực và dự án cho các bài test.
 */
trait BuildsProjects
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeActiveContract(array $attributes = []): Contract
    {
        return Contract::factory()->active()->create($attributes);
    }

    /**
     * Dự án doanh thu 100.000.000 (mặc định) thuộc một hợp đồng đang hiệu lực.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProject(array $attributes = []): Project
    {
        return Project::factory()->create($attributes)->refresh();
    }
}
