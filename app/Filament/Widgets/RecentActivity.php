<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Support\Access;
use App\Models\AuditLog;
use Filament\Widgets\Widget;

/**
 * "Hoạt động gần đây" (mục 41): các dòng mới nhất của nhật ký thao tác. Người
 * có quyền xem Audit Log thấy hoạt động của mọi người; người khác chỉ thấy
 * hoạt động của chính mình.
 */
class RecentActivity extends Widget
{
    private const LIMIT = 8;

    protected static ?int $sort = 60;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.recent-activity';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $seesAll = Access::can('audit_logs.view');

        $logs = AuditLog::query()
            ->when(! $seesAll, fn ($query) => $query->where('user_id', auth()->id()))
            // Đăng nhập không phải là hoạt động nghiệp vụ.
            ->where('action', '!=', 'login')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return [
            'logs' => $logs,
            'seesAll' => $seesAll,
            'allUrl' => $seesAll ? AuditLogResource::getUrl('index') : null,
        ];
    }
}
