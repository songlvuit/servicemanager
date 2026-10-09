<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use Auditable;

    public const AUDIT_NAME = 'Người dùng';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'position',
        'hourly_rate',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'calendar_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'hourly_rate' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tài khoản bị khoá (is_active = false) không vào được hệ thống.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(SystemRole::SuperAdmin->value);
    }

    /**
     * Các lịch làm việc người này tham gia.
     *
     * @return BelongsToMany<ScheduleEntry, $this>
     */
    public function scheduleEntries(): BelongsToMany
    {
        return $this->belongsToMany(ScheduleEntry::class, 'schedule_entry_user')->withTimestamps();
    }

    /**
     * Mã bí mật của đường dẫn đăng ký lịch cá nhân; tạo khi dùng lần đầu.
     */
    public function calendarToken(): string
    {
        if (blank($this->calendar_token)) {
            $this->rotateCalendarToken();
        }

        return $this->calendar_token;
    }

    /**
     * Đổi mã: đường dẫn cũ lập tức hết hiệu lực.
     */
    public function rotateCalendarToken(): string
    {
        $this->forceFill(['calendar_token' => Str::random(48)])->saveQuietly();

        return $this->calendar_token;
    }
}
