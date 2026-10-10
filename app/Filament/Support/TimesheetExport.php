<?php

namespace App\Filament\Support;

use App\Models\Timesheet;
use App\Support\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Nút "Xuất bảng công (Excel)" của màn hình Chấm công: một file .xlsx gồm ba
 * trang tính, lấy ĐÚNG những dòng công đang hiển thị (theo nhân viên, tháng,
 * khoảng ngày, công việc… đang lọc):
 *
 *   1. Theo nhân viên — mỗi người, từng công việc: số ngày, số buổi, thành tiền; có dòng cộng từng người.
 *   2. Theo công việc — mỗi công việc, từng người; có dòng cộng từng công việc.
 *   3. Chi tiết       — từng dòng công theo ngày.
 *
 * Cột đơn giá và thành tiền chỉ có khi người xuất được xem chi phí nội bộ.
 * Mỗi lần xuất được ghi vào nhật ký thao tác.
 */
class TimesheetExport
{
    public const PERMISSION = 'timesheets.export';

    private const OVERHEAD = 'Không gắn công việc (chi phí chung)';

    public static function make(): Action
    {
        return Action::make('exportLabor')
            ->label('Xuất bảng công (Excel)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->tooltip('File Excel gồm 3 trang: tổng hợp theo nhân viên, theo công việc và chi tiết từng ngày — theo đúng bộ lọc đang chọn.')
            ->visible(fn (): bool => Access::can(self::PERMISSION))
            ->action(function (HasTable $livewire): BinaryFileResponse {
                abort_unless(Access::can(self::PERMISSION), 403);

                $rows = $livewire->getTableQueryForExport()
                    ->with(['user', 'task.customer'])
                    ->get()
                    ->sortBy(fn (Timesheet $row): string => $row->work_date->toDateString().'|'.$row->getKey())
                    ->values();

                AuditLogger::record(
                    'exported',
                    label: 'Bảng công',
                    description: 'XLSX, '.number_format($rows->count(), 0, ',', '.').' dòng',
                );

                return static::download($rows, Access::canSeeCosts());
            });
    }

    /**
     * @param  Collection<int, Timesheet>  $rows
     */
    public static function download(Collection $rows, bool $withMoney): BinaryFileResponse
    {
        $path = storage_path('app/private/exports/'.Str::uuid().'.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        foreach (static::sheets($rows, $withMoney) as $index => $sheet) {
            $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName($sheet['name']);

            foreach ($sheet['widths'] as $column => $width) {
                $current->setColumnWidth($width, $column + 1);
            }

            foreach ($sheet['rows'] as [$values, $bold]) {
                $writer->addRow(Row::fromValues($values, $bold ? (new Style)->setFontBold() : null));
            }
        }

        $writer->close();

        return response()
            ->download($path, 'bang-cong-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /**
     * Nội dung ba trang tính. Mỗi dòng là [các ô, in đậm?].
     *
     * @param  Collection<int, Timesheet>  $rows
     * @return list<array{name: string, widths: list<int>, rows: list<array{0: list<string|int|float|null>, 1: bool}>}>
     */
    public static function sheets(Collection $rows, bool $withMoney): array
    {
        $period = $rows->isEmpty()
            ? 'Không có dòng công nào'
            : 'Kỳ: '.$rows->min('work_date')->format('d/m/Y').' – '.$rows->max('work_date')->format('d/m/Y');
        $money = fn (array $cells): array => $withMoney ? $cells : [];
        $person = fn (Timesheet $row): string => $row->user?->name ?? 'Người dùng đã xoá';
        $task = fn (Timesheet $row): string => $row->task?->name ?? self::OVERHEAD;
        $customer = fn (Timesheet $row): ?string => $row->task?->customer?->name;

        // Một dòng tổng hợp của (nhân viên, công việc).
        $line = function (Collection $group) use ($money): array {
            $rates = $group->pluck('session_rate')->map(fn (mixed $rate): float => (float) $rate)->unique();

            return [
                $group->pluck('work_date')->map->toDateString()->unique()->count(),
                static::number($group->sum('sessions')),
                // Đơn giá chỉ ghi khi mọi dòng cùng một mức.
                ...$money([$rates->count() === 1 ? $rates->first() : 'nhiều mức', static::number($group->sum('cost'))]),
            ];
        };
        $total = fn (Collection $group): array => [
            $group->map(fn (Timesheet $row): string => $row->user_id.'|'.$row->work_date->toDateString())->unique()->count(),
            static::number($group->sum('sessions')),
            ...$money([null, static::number($group->sum('cost'))]),
        ];

        // 1. Theo nhân viên.
        $byPerson = [
            [['BẢNG CÔNG THEO NHÂN VIÊN'], true],
            [[$period], false],
            [[], false],
            [['Nhân viên', 'Công việc', 'Khách hàng', 'Số ngày', 'Số buổi (công)', ...$money(['Đơn giá / buổi', 'Thành tiền'])], true],
        ];

        foreach ($rows->groupBy($person)->sortKeys() as $name => $ofPerson) {
            foreach ($ofPerson->groupBy(fn (Timesheet $row): string => (string) ($row->task_id ?? 0)) as $ofTask) {
                $byPerson[] = [[$name, $task($ofTask->first()), $customer($ofTask->first()), ...$line($ofTask)], false];
            }

            $byPerson[] = [['Cộng: '.$name, null, null, ...$total($ofPerson)], true];
        }

        $byPerson[] = [['TỔNG CỘNG', null, null, ...$total($rows)], true];

        // 2. Theo công việc.
        $byTask = [
            [['BẢNG CÔNG THEO CÔNG VIỆC'], true],
            [[$period], false],
            [[], false],
            [['Công việc', 'Khách hàng', 'Nhân viên', 'Số ngày', 'Số buổi (công)', ...$money(['Đơn giá / buổi', 'Thành tiền'])], true],
        ];

        foreach ($rows->groupBy(fn (Timesheet $row): string => (string) ($row->task_id ?? 0)) as $ofTask) {
            foreach ($ofTask->groupBy($person)->sortKeys() as $name => $ofPerson) {
                $byTask[] = [[$task($ofTask->first()), $customer($ofTask->first()), $name, ...$line($ofPerson)], false];
            }

            $byTask[] = [['Cộng: '.$task($ofTask->first()), null, null, ...$total($ofTask)], true];
        }

        $byTask[] = [['TỔNG CỘNG', null, null, ...$total($rows)], true];

        // 3. Chi tiết.
        $detail = [
            [['Ngày', 'Nhân viên', 'Công việc', 'Khách hàng', 'Nội dung', 'Số buổi (công)', ...$money(['Đơn giá / buổi', 'Thành tiền']), 'Nguồn'], true],
        ];

        foreach ($rows as $row) {
            $detail[] = [[
                $row->work_date->format('d/m/Y'),
                $person($row),
                $task($row),
                $customer($row),
                TableExport::value($row->description),
                static::number($row->sessions),
                ...$money([static::number($row->session_rate), static::number($row->cost)]),
                $row->is_auto ? 'Tự động' : 'Nhập tay',
            ], false];
        }

        return [
            ['name' => 'Theo nhân viên', 'widths' => [28, 40, 30, 10, 16, 16, 18], 'rows' => $byPerson],
            ['name' => 'Theo công việc', 'widths' => [40, 30, 28, 10, 16, 16, 18], 'rows' => $byTask],
            ['name' => 'Chi tiết', 'widths' => [12, 28, 40, 30, 36, 16, 16, 18, 12], 'rows' => $detail],
        ];
    }

    /**
     * Số ghi ra Excel là số (không phải chữ) để cộng, lọc được.
     */
    private static function number(mixed $value): int|float
    {
        $number = round((float) $value, 2);

        return $number === floor($number) ? (int) $number : $number;
    }
}
