<?php

namespace App\Filament\Support;

use App\Support\Audit\AuditLogger;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Nút "Xuất dữ liệu" (mục 63) cho một màn hình có bảng: xuất ra CSV hoặc Excel
 * ĐÚNG những dòng đang hiển thị — theo tab, bộ lọc, ô tìm kiếm và thứ tự sắp
 * xếp hiện tại — không giới hạn ở trang đang xem.
 *
 *   TableExport::make('customers.export', 'khach-hang', 'Danh sách khách hàng', fn (): array => [
 *       'Mã' => 'code',
 *       'Tên' => 'name',
 *       'Phụ trách' => 'salesOwner.name',
 *       'Số báo giá' => fn (Customer $customer): int => $customer->quotations_count,
 *   ])
 *
 * Mỗi cột là một đường dẫn thuộc tính (data_get) hoặc một closure nhận bản ghi.
 * Mỗi lần xuất được ghi vào nhật ký thao tác.
 */
class TableExport
{
    /**
     * @param  Closure(): array<string, string|Closure>  $columns
     */
    public static function make(string $permission, string $filename, string $title, Closure $columns): ActionGroup
    {
        return ActionGroup::make([
            static::action('csv', 'Xuất CSV', $permission, $filename, $title, $columns),
            static::action('xlsx', 'Xuất Excel (.xlsx)', $permission, $filename, $title, $columns),
        ])
            ->label('Xuất dữ liệu')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->button()
            ->visible(fn (): bool => Access::can($permission));
    }

    /**
     * @param  Closure(): array<string, string|Closure>  $columns
     */
    private static function action(string $format, string $label, string $permission, string $filename, string $title, Closure $columns): Action
    {
        return Action::make('export'.Str::studly($format))
            ->label($label)
            ->icon($format === 'csv' ? Heroicon::OutlinedDocumentText : Heroicon::OutlinedTableCells)
            ->action(function (HasTable $livewire) use ($format, $permission, $filename, $title, $columns): StreamedResponse|BinaryFileResponse {
                abort_unless(Access::can($permission), 403);

                $query = $livewire->getTableQueryForExport();
                $columns = $columns();
                $count = (clone $query)->toBase()->getCountForPagination();
                $name = $filename.'-'.now()->format('Ymd-His').'.'.$format;

                AuditLogger::record(
                    'exported',
                    label: $title,
                    description: Str::upper($format).', '.number_format($count, 0, ',', '.').' dòng',
                );

                $rows = (function () use ($query, $columns): iterable {
                    foreach ($query->lazy(500) as $record) {
                        yield static::row($record, $columns);
                    }
                })();

                return $format === 'csv'
                    ? static::csv($name, array_keys($columns), $rows)
                    : static::xlsx($name, array_keys($columns), $rows);
            });
    }

    /**
     * @param  array<string, string|Closure>  $columns
     * @return list<string|int|float|null>
     */
    public static function row(Model $record, array $columns): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[] = static::value($column instanceof Closure ? $column($record) : data_get($record, $column));
        }

        return $row;
    }

    /**
     * Đưa giá trị về dạng ghi được ra file: nhãn tiếng Việt cho trạng thái,
     * ngày DD/MM/YYYY, số giữ nguyên là số để Excel tính toán được.
     */
    public static function value(mixed $value): string|int|float|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof HasLabel => (string) $value->getLabel(),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i'),
            is_bool($value) => $value ? 'Có' : 'Không',
            is_int($value), is_float($value) => $value,
            // Cột DECIMAL (tiền, tỷ lệ) được Eloquent trả về dạng "1234.50". Chuỗi
            // toàn chữ số (mã số thuế, số điện thoại, số tài khoản) giữ nguyên là chữ.
            is_string($value) && preg_match('/^-?\d+\.\d{1,4}$/', $value) === 1 => (float) $value,
            is_scalar($value) => static::safeText((string) $value),
            default => static::safeText((string) json_encode($value, JSON_UNESCAPED_UNICODE)),
        };
    }

    /**
     * Chặn "CSV injection": ô chữ bắt đầu bằng = + - @ sẽ bị Excel hiểu là
     * công thức, nên thêm dấu nháy đơn phía trước.
     */
    private static function safeText(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * @param  list<string>  $headings
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    private static function csv(string $name, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $output = fopen('php://output', 'w');

            // BOM để Excel mở đúng tiếng Việt (UTF-8).
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headings, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $headings
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    private static function xlsx(string $name, array $headings, iterable $rows): BinaryFileResponse
    {
        $path = storage_path('app/private/exports/'.Str::uuid().'.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headings, (new Style)->setFontBold()));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
