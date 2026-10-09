<?php

namespace App\Services\Sequences;

use Illuminate\Support\Facades\DB;

/**
 * Sinh mã chứng từ tăng dần, không trùng kể cả khi nhiều người tạo cùng lúc:
 * CUS-2026-0001, OPP-2026-0001, QT-2026-0001, SV-0001...
 *
 * Mỗi (prefix, năm) có một dòng trong bảng document_sequences; dòng này được
 * khoá (SELECT ... FOR UPDATE) trong lúc lấy số kế tiếp. Số đã cấp không bao
 * giờ dùng lại, kể cả khi bản ghi bị xoá.
 */
class DocumentSequence
{
    public const TABLE = 'document_sequences';

    public function next(string $prefix, bool $yearly = true, int $padding = 4): string
    {
        $year = $yearly ? (int) now()->format('Y') : 0;

        $number = DB::transaction(function () use ($prefix, $year): int {
            DB::table(self::TABLE)->insertOrIgnore([
                'prefix' => $prefix,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table(self::TABLE)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table(self::TABLE)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });

        $formatted = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        return $yearly ? "{$prefix}-{$year}-{$formatted}" : "{$prefix}-{$formatted}";
    }
}
