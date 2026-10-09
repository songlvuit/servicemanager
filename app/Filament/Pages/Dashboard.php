<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    public function getHeading(): string|Htmlable|null
    {
        $name = auth()->user()?->name ?? '';

        return static::greeting().', '.$name.' 👋';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Tổng quan hoạt động của công ty — '.now()->format('d/m/Y');
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    public static function greeting(?int $hour = null): string
    {
        $hour ??= (int) now()->format('G');

        return match (true) {
            $hour < 11 => 'Chào buổi sáng',
            $hour < 14 => 'Chào buổi trưa',
            $hour < 18 => 'Chào buổi chiều',
            default => 'Chào buổi tối',
        };
    }
}
