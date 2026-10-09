<?php

namespace App\Filament\Support;

use Filament\Support\RawJs;

/**
 * Màu và tuỳ chọn Chart.js dùng chung cho các biểu đồ.
 *
 * - Biểu đồ MỘT chuỗi số liệu dùng màu chủ đạo của giao diện (để Filament tự
 *   tô, đúng cả ở chế độ tối).
 * - Biểu đồ NHIỀU chuỗi / nhiều nhóm dùng bảng màu SERIES theo đúng thứ tự,
 *   không xoay vòng: nhóm thứ 9 trở đi phải gộp vào "Khác". Bảng màu đã được
 *   kiểm tra phân biệt được với người mù màu trên cả nền sáng và nền tối.
 * - Màu trạng thái (tốt / cảnh báo / nghiêm trọng / nguy hiểm) chỉ dùng để
 *   diễn tả tình trạng, không dùng làm "màu của chuỗi thứ n".
 */
class ChartPalette
{
    public const SERIES = [
        '#3987e5',
        '#d95926',
        '#199e70',
        '#c98500',
        '#d55181',
        '#008300',
        '#9085e9',
        '#e66767',
    ];

    /** Màu tô cột của biểu đồ một chuỗi: trùng màu chủ đạo (Indigo) của giao diện. */
    public const PRIMARY = '#6366f1';

    /** Nhóm gộp "Khác" / "Chưa phân loại". */
    public const OTHER = '#898781';

    public const GOOD = '#0ca30c';

    public const WARNING = '#fab219';

    public const SERIOUS = '#ec835a';

    public const CRITICAL = '#d03b3b';

    /**
     * $count màu đầu tiên của bảng màu.
     *
     * @return list<string>
     */
    public static function series(int $count): array
    {
        return array_slice(self::SERIES, 0, max(0, $count));
    }

    /**
     * Màu cho các nhóm có tên: nhóm "Khác"/"Chưa phân loại" luôn màu xám.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $neutralLabels
     * @return list<string>
     */
    public static function forLabels(array $labels, array $neutralLabels = []): array
    {
        $colors = [];
        $next = 0;

        foreach ($labels as $label) {
            $colors[] = in_array($label, $neutralLabels, true)
                ? self::OTHER
                : (self::SERIES[$next++] ?? self::OTHER);
        }

        return $colors;
    }

    /**
     * Tuỳ chọn cho biểu đồ đường / cột có trục giá trị là tiền VND: nhãn trục
     * rút gọn ("45 tr", "1,5 tỷ"), tooltip ghi đủ số ("45.000.000 ₫").
     */
    public static function moneyAxis(bool $legend = false, bool $horizontal = false): RawJs
    {
        $valueAxis = $horizontal ? 'x' : 'y';
        $categoryAxis = $horizontal ? 'y' : 'x';
        $legendJs = $legend ? "{ display: true, position: 'bottom', labels: { usePointStyle: true, boxHeight: 8 } }" : '{ display: false }';
        $indexAxis = $horizontal ? "indexAxis: 'y'," : '';

        return RawJs::make(<<<JS
            {
                {$indexAxis}
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {$legendJs},
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.parsed.{$valueAxis} ?? 0;
                                const name = context.dataset.label ? context.dataset.label + ': ' : '';

                                return ' ' + name + new Intl.NumberFormat('vi-VN').format(Math.round(value)) + ' ₫';
                            },
                        },
                    },
                },
                scales: {
                    {$valueAxis}: {
                        beginAtZero: true,
                        grid: { display: true },
                        ticks: {
                            maxTicksLimit: 6,
                            callback: (value) => {
                                const absolute = Math.abs(value);
                                const trim = (number) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 1 }).format(number);

                                if (absolute >= 1e9) return trim(value / 1e9) + ' tỷ';
                                if (absolute >= 1e6) return trim(value / 1e6) + ' tr';

                                return new Intl.NumberFormat('vi-VN').format(value);
                            },
                        },
                    },
                    {$categoryAxis}: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkipPadding: 12 },
                    },
                },
            }
        JS);
    }

    /**
     * Tuỳ chọn cho biểu đồ tròn rỗng: chú giải bên dưới, tooltip ghi giá trị và tỷ trọng.
     */
    public static function doughnut(bool $money = true): RawJs
    {
        $suffix = $money ? " + ' ₫'" : '';

        return RawJs::make(<<<JS
            {
                cutout: '62%',
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxHeight: 8 } },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const total = context.dataset.data.reduce((sum, value) => sum + Number(value), 0);
                                const share = total > 0 ? Math.round((Number(context.parsed) / total) * 100) : 0;

                                return ' ' + new Intl.NumberFormat('vi-VN').format(Math.round(context.parsed)){$suffix} + ' (' + share + '%)';
                            },
                        },
                    },
                },
            }
        JS);
    }
}
