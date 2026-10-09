<?php

namespace App\Models\Concerns;

use App\Models\Project;
use App\Services\Projects\ProjectFinancials;
use Illuminate\Database\Eloquent\Model;

/**
 * Chứng từ làm thay đổi chi phí / công nợ của dự án (đề nghị chi, mua hàng,
 * chấm công, hóa đơn...). Mỗi lần lưu hoặc xoá, số liệu của dự án liên quan
 * được tính lại — kể cả dự án cũ nếu chứng từ vừa được chuyển sang dự án khác.
 */
trait AffectsProjectCosts
{
    protected static function bootAffectsProjectCosts(): void
    {
        $recalculate = function (Model $model): void {
            $projectIds = array_unique(array_filter([
                $model->getAttribute('project_id'),
                $model->getOriginal('project_id'),
            ]));

            foreach (Project::query()->withTrashed()->whereKey($projectIds)->get() as $project) {
                app(ProjectFinancials::class)->recalculate($project);
            }
        };

        static::saved($recalculate);
        static::deleted($recalculate);

        if (method_exists(static::class, 'restored')) {
            static::restored($recalculate);
        }
    }
}
