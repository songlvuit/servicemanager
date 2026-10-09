<?php

namespace App\Enums\Concerns;

/**
 * Dùng cho các enum trạng thái có khai báo allowedTransitions().
 */
trait HasTransitions
{
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Trạng thái kết thúc: không còn bước chuyển nào.
     */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
