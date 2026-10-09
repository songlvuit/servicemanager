<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\ScheduleColors;
use Database\Factories\ScheduleTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loại lịch: quay phim, livestream, họp… hoặc nghỉ phép (is_leave).
 */
class ScheduleType extends Model
{
    use Auditable;

    /** @use HasFactory<ScheduleTypeFactory> */
    use HasFactory;

    public const AUDIT_NAME = 'Loại lịch';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
        'is_leave',
        'sort_order',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'color' => ScheduleColors::DEFAULT,
        'is_leave' => false,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_leave' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function hex(): string
    {
        return ScheduleColors::hex($this->color);
    }

    /**
     * @param  Builder<ScheduleType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<ScheduleType>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Loại dùng cho "Đăng ký nghỉ": loại nghỉ phép đang dùng, xếp đầu tiên.
     */
    public static function leave(): ?self
    {
        return static::query()->active()->where('is_leave', true)->ordered()->first();
    }

    /**
     * @return HasMany<ScheduleEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}
