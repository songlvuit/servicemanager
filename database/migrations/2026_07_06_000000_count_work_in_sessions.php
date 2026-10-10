<?php

use App\Services\Tasks\TaskFinancials;
use App\Services\Tasks\TaskLabor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHẤM CÔNG THEO BUỔI (CÔNG) thay cho theo giờ, và công của nhân sự tự sinh
 * theo phân công của công việc.
 *
 *   - users.hourly_rate       → session_rate (đơn giá một buổi)
 *   - timesheets.hours        → sessions (số buổi), hourly_rate → session_rate
 *   - tasks.estimated_hours   → estimated_sessions, actual_hours → actual_sessions
 *   - timesheets.is_auto      : dòng công do hệ thống tự sinh từ phân công (xem TaskLabor)
 *
 * Số liệu đang có được quy đổi theo "một buổi = N giờ" (cấu hình job.session_hours,
 * mặc định 4): số buổi = số giờ ÷ N, đơn giá buổi = đơn giá giờ × N. Chi phí đã
 * ghi nhận (timesheets.cost) giữ nguyên từng đồng.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hours = max(1, (int) config('job.session_hours', 4));

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('hourly_rate', 'session_rate');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('hours', 'sessions');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('hourly_rate', 'session_rate');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false);
            $table->index(['task_id', 'is_auto']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('estimated_hours', 'estimated_sessions');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('actual_hours', 'actual_sessions');
        });

        DB::table('users')->update(['session_rate' => DB::raw("session_rate * {$hours}")]);
        DB::table('timesheets')->update([
            'sessions' => DB::raw("ROUND(sessions / {$hours}.0, 2)"),
            'session_rate' => DB::raw("session_rate * {$hours}"),
        ]);
        DB::table('tasks')->update([
            'estimated_sessions' => DB::raw("ROUND(estimated_sessions / {$hours}.0, 2)"),
            'actual_sessions' => DB::raw("ROUND(actual_sessions / {$hours}.0, 2)"),
        ]);

        // Công việc đã phân công từ trước cũng có công của từng người (ngày nào
        // đã chấm công rồi thì giữ nguyên). Không gửi thông báo "vượt ngân sách" cho số liệu cũ.
        TaskFinancials::silently(fn () => app(TaskLabor::class)->backfill());
    }

    public function down(): void
    {
        $hours = max(1, (int) config('job.session_hours', 4));

        DB::table('timesheets')->where('is_auto', true)->delete();

        DB::table('tasks')->update([
            'estimated_sessions' => DB::raw("estimated_sessions * {$hours}"),
            'actual_sessions' => DB::raw("actual_sessions * {$hours}"),
        ]);
        DB::table('timesheets')->update([
            'sessions' => DB::raw("sessions * {$hours}"),
            'session_rate' => DB::raw("ROUND(session_rate / {$hours}.0, 2)"),
        ]);
        DB::table('users')->update(['session_rate' => DB::raw("ROUND(session_rate / {$hours}.0, 2)")]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('actual_sessions', 'actual_hours');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('estimated_sessions', 'estimated_hours');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'is_auto']);
            $table->dropColumn('is_auto');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('session_rate', 'hourly_rate');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('sessions', 'hours');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('session_rate', 'hourly_rate');
        });
    }
};
