<?php

namespace App\Support;

use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Cấu hình sửa được trên giao diện (Hệ thống → Cấu hình).
 *
 * Mỗi mục là một khoá config sẵn có (ví dụ "quotation.low_margin_threshold").
 * Giá trị lưu trong bảng `settings` được nạp đè lên config khi ứng dụng khởi
 * động, nên toàn bộ code vẫn chỉ đọc config('...') như bình thường. Mục nào
 * chưa lưu thì dùng giá trị mặc định trong config/*.php (.env).
 */
class Settings
{
    public const CACHE_KEY = 'app.settings';

    public const TYPE_TEXT = 'text';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_PERCENT = 'percent';

    /**
     * Các mục được phép sửa, theo nhóm hiển thị.
     *
     * @return array<string, array<string, array{label: string, type: string, help?: string, min?: int, max?: int}>>
     */
    public static function groups(): array
    {
        return [
            'Thông tin công ty' => [
                'company.name' => ['label' => 'Tên công ty', 'type' => self::TYPE_TEXT],
                'company.tax_code' => ['label' => 'Mã số thuế', 'type' => self::TYPE_TEXT],
                'company.address' => ['label' => 'Địa chỉ', 'type' => self::TYPE_TEXT],
                'company.phone' => ['label' => 'Điện thoại', 'type' => self::TYPE_TEXT],
                'company.email' => ['label' => 'Email', 'type' => self::TYPE_TEXT],
            ],
            'Báo giá' => [
                'quotation.validity_days' => ['label' => 'Số ngày hiệu lực mặc định', 'type' => self::TYPE_INTEGER, 'min' => 1, 'max' => 365],
                'quotation.default_tax_rate' => ['label' => 'Thuế GTGT (VAT) mặc định của báo giá', 'type' => self::TYPE_PERCENT],
                'quotation.low_margin_threshold' => [
                    'label' => 'Ngưỡng cảnh báo biên lợi nhuận thấp',
                    'type' => self::TYPE_PERCENT,
                    'help' => 'Biên lợi nhuận ước tính dưới mức này thì báo giá hiện cảnh báo "Biên lợi nhuận thấp."',
                ],
                'quotation.director_discount_threshold' => [
                    'label' => 'Ngưỡng chiết khấu cần Giám đốc duyệt',
                    'type' => self::TYPE_PERCENT,
                    'help' => 'Chiết khấu vượt mức này thì báo giá phải do Giám đốc duyệt.',
                ],
            ],
            'Công việc & hợp đồng' => [
                'job.budget_warning_threshold' => [
                    'label' => 'Ngưỡng cảnh báo ngân sách công việc',
                    'type' => self::TYPE_PERCENT,
                    'help' => 'Chi phí thực tế đạt từ mức này của ngân sách thì công việc hiện cảnh báo.',
                ],
                'job.contract_expiry_warning_days' => ['label' => 'Nhắc hợp đồng sắp hết hạn trước (ngày)', 'type' => self::TYPE_INTEGER, 'min' => 0, 'max' => 365],
                'job.margin_good' => [
                    'label' => 'Biên lợi nhuận "tốt" từ trên',
                    'type' => self::TYPE_PERCENT,
                    'help' => 'Hợp đồng có biên lợi nhuận trên mức này được đánh dấu là tốt.',
                ],
                'job.margin_warning' => [
                    'label' => 'Biên lợi nhuận "cảnh báo" dưới',
                    'type' => self::TYPE_PERCENT,
                    'help' => 'Hợp đồng có biên lợi nhuận dưới mức này được đánh dấu cảnh báo trong báo cáo lợi nhuận.',
                ],
                'job.margin_danger' => [
                    'label' => 'Biên lợi nhuận "nguy hiểm" dưới',
                    'type' => self::TYPE_PERCENT,
                ],
            ],
            'Hóa đơn & công nợ' => [
                'billing.payment_term_days' => ['label' => 'Hạn thanh toán mặc định (ngày)', 'type' => self::TYPE_INTEGER, 'min' => 0, 'max' => 365],
                'billing.default_tax_rate' => ['label' => 'Thuế GTGT (VAT) mặc định của hóa đơn', 'type' => self::TYPE_PERCENT],
                'billing.due_reminder_days' => ['label' => 'Nhắc hóa đơn sắp tới hạn trước (ngày)', 'type' => self::TYPE_INTEGER, 'min' => 0, 'max' => 365],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, type: string, help?: string, min?: int, max?: int}>
     */
    public static function definitions(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * Nạp các giá trị đã lưu đè lên config. Gọi khi ứng dụng khởi động; nếu
     * chưa có bảng `settings` (chưa chạy migration) hoặc cache / database
     * không sẵn sàng thì giữ nguyên giá trị mặc định.
     */
    public static function apply(): void
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => self::load());
        } catch (Throwable) {
            return;
        }

        self::override($stored);
    }

    /**
     * Giá trị hiện hành của mọi mục (đã lưu hoặc mặc định), khoá là khoá config.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $values = [];

        foreach (array_keys(self::definitions()) as $key) {
            $values[$key] = config($key);
        }

        return $values;
    }

    /**
     * Lưu các mục được gửi lên (chỉ nhận khoá đã khai báo), áp dụng ngay và
     * ghi nhật ký những mục thay đổi.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $definitions = self::definitions();
        $old = [];
        $new = [];

        DB::transaction(function () use ($values, $definitions, &$old, &$new): void {
            foreach ($values as $key => $value) {
                if (! isset($definitions[$key])) {
                    throw new InvalidArgumentException("Mục cấu hình [{$key}] không tồn tại.");
                }

                $value = self::cast($value, $definitions[$key]['type']);
                $before = config($key);

                if ($before == $value) {
                    continue;
                }

                $old[$key] = $before;
                $new[$key] = $value;

                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        Cache::forget(self::CACHE_KEY);
        self::override($new);

        if ($new !== []) {
            $labels = array_map(fn (string $key): string => $definitions[$key]['label'], array_keys($new));

            AuditLogger::record('settings_updated', label: 'Cấu hình hệ thống', description: 'Đổi: '.implode(', ', $labels), old: $old, new: $new);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        return DB::table('settings')
            ->pluck('value', 'key')
            ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function override(array $values): void
    {
        $definitions = self::definitions();

        foreach ($values as $key => $value) {
            if (isset($definitions[$key])) {
                config([$key => self::cast($value, $definitions[$key]['type'])]);
            }
        }
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_PERCENT => (float) $value,
            default => filled($value) ? trim((string) $value) : null,
        };
    }
}
