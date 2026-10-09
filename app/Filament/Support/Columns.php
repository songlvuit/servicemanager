<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;

/**
 * Cột bảng và ô hiển thị dùng chung, để tiền và mã chứng từ trông giống nhau
 * ở mọi màn hình.
 */
class Columns
{
    /**
     * Cột mã chứng từ (CUS-..., QT-..., PRJ-...).
     */
    public static function code(string $name = 'code', string $label = 'Mã'): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->weight(FontWeight::Medium)
            ->searchable()
            ->sortable();
    }

    /**
     * Cột tiền VND, căn phải, sắp xếp được.
     */
    public static function money(string $name, string $label, bool $summarize = false): TextColumn
    {
        $column = TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0))
            ->alignEnd()
            ->sortable();

        if ($summarize) {
            $column->summarize(
                Sum::make()
                    ->label('Tổng')
                    ->formatStateUsing(fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0)),
            );
        }

        return $column;
    }

    /**
     * Ô tiền VND trên trang chi tiết.
     */
    public static function moneyEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->formatStateUsing(fn (mixed $state): string => Money::format(is_scalar($state) ? $state : 0));
    }
}
