<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ActionItems;
use Filament\Widgets\Widget;

/**
 * "Cần xử lý" (mục 36): các con số cần hành động, bấm vào mở đúng danh sách.
 */
class PendingActions extends Widget
{
    protected static ?int $sort = 50;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.pending-actions';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'items' => $user ? ActionItems::for($user) : [],
        ];
    }
}
