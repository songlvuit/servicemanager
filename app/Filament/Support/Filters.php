<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Bộ lọc bảng dùng chung.
 */
class Filters
{
    /**
     * Chỉ hiện những dòng KHÔNG gắn công việc nào (chi phí chung của công ty).
     */
    public static function withoutTask(string $label = 'Chi phí chung (không gắn công việc)'): Filter
    {
        return Filter::make('without_task')
            ->label($label)
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->whereNull($query->getModel()->qualifyColumn('task_id')));
    }

    /**
     * Lọc theo khoảng ngày "Từ ngày – Đến ngày" trên một cột ngày.
     */
    public static function dateRange(string $column, string $label): Filter
    {
        return Filter::make($column.'_range')
            ->schema([
                DatePicker::make('from')
                    ->label("{$label} từ")
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                DatePicker::make('until')
                    ->label('đến')
                    ->native(false)
                    ->displayFormat('d/m/Y'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date)))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = "{$label} từ ".Carbon::parse($data['from'])->format('d/m/Y');
                }

                if ($data['until'] ?? null) {
                    $indicators[] = "{$label} đến ".Carbon::parse($data['until'])->format('d/m/Y');
                }

                return $indicators;
            });
    }
}
