<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lịch làm việc chung của công ty: ai làm việc gì, ở đâu, cho dự án nào.
     */
    public function up(): void
    {
        // Loại lịch (quay phim, livestream, nghỉ phép…) — sửa được ở Hệ thống → Loại lịch.
        Schema::create('schedule_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('color', 20)->default('blue');   // khoá màu, xem App\Support\ScheduleColors
            $table->boolean('is_leave')->default(false);    // nghỉ phép / bận: không phải việc của khách
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('schedule_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->string('location')->nullable();
            $table->text('equipment')->nullable();             // thiết bị cần mang
            $table->string('external_crew')->nullable();       // cộng tác viên ngoài hệ thống
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->timestamp('timesheets_logged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
            $table->index('schedule_type_id');
            $table->index('project_id');
            $table->index('customer_id');
            $table->index('status');
        });

        // Ê-kíp của một lịch.
        Schema::create('schedule_entry_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['schedule_entry_id', 'user_id']);
            $table->index('user_id');
        });

        // Giờ công đã ghi từ một lịch (để không ghi trùng và biết giờ công nào đến từ lịch nào).
        // Dùng bảng nối thay vì thêm cột vào `timesheets`, vì view project_cost_lines đang đọc bảng đó.
        Schema::create('schedule_entry_timesheet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('timesheet_id');
            $table->index('schedule_entry_id');
        });

        // Mã bí mật của đường dẫn đăng ký lịch cá nhân (Google Calendar, lịch điện thoại).
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique();
        });

        // Các loại lịch mặc định.
        $now = now();
        $type = fn (string $name, string $color, int $order, bool $leave = false): array => [
            'name' => $name,
            'color' => $color,
            'is_leave' => $leave,
            'sort_order' => $order,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('schedule_types')->insert([
            $type('Livestream', 'blue', 10),
            $type('Quay phim', 'orange', 20),
            $type('Chụp ảnh', 'green', 30),
            $type('Màn hình LED', 'pink', 40),
            $type('Hậu kỳ', 'violet', 50),
            $type('Họp / khảo sát', 'amber', 60),
            $type('Nghỉ phép / bận', 'gray', 90, true),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });

        Schema::dropIfExists('schedule_entry_timesheet');
        Schema::dropIfExists('schedule_entry_user');
        Schema::dropIfExists('schedule_entries');
        Schema::dropIfExists('schedule_types');
    }
};
