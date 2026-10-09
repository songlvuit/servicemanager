<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Đánh dấu một nhắc việc đã gửi, để mỗi sự kiện chỉ được nhắc một lần.
 */
class ReminderLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Trả về true nếu đây là lần đầu ghi nhận $key (tức là cần gửi nhắc).
     */
    public static function claim(string $key): bool
    {
        return static::query()->firstOrCreate(['key' => $key], ['sent_at' => now()])->wasRecentlyCreated;
    }
}
