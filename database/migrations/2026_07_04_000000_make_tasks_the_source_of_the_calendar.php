<?php

use App\Services\Scheduling\ScheduleTasks;
use App\Services\Scheduling\TaskSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CÔNG VIỆC là nguồn của Lịch làm việc: thông tin lịch (loại lịch, giờ, ê-kíp,
 * địa điểm, thiết bị…) được nhập ở công việc, và mỗi công việc có ngày bắt
 * đầu tự có một lịch tương ứng trên Lịch làm việc.
 *
 *   - tasks: thêm các cột thông tin lịch;
 *   - schedule_entries.from_task: lịch này là "bản chiếu" của công việc
 *     (task_id) và luôn được cập nhật theo công việc đó.
 *
 * Dữ liệu cũ được nối lại ngay trong migration này: lịch chưa có công việc
 * thì tạo công việc, công việc có ngày bắt đầu mà chưa có lịch thì tạo lịch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            // Không đặt khoá ngoại: loại lịch / khách hàng bị xoá thì công việc dùng giá trị mặc định.
            $table->unsignedBigInteger('schedule_type_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_all_day')->default(true);
            $table->string('start_time', 5)->nullable();   // "HH:MM"
            $table->string('end_time', 5)->nullable();
            $table->json('crew_ids')->nullable();
            $table->string('location')->nullable();
            $table->text('equipment')->nullable();
            $table->string('external_crew')->nullable();
            $table->boolean('is_tentative')->default(false);
        });

        Schema::table('schedule_entries', function (Blueprint $table): void {
            $table->boolean('from_task')->default(false);
        });

        // Cài mới thì chưa có lịch / công việc nào nên hai bước này không làm gì.
        app(ScheduleTasks::class)->backfill();
        app(TaskSchedule::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table): void {
            $table->dropColumn('from_task');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'schedule_type_id', 'customer_id', 'is_all_day', 'start_time', 'end_time',
                'crew_ids', 'location', 'equipment', 'external_crew', 'is_tentative',
            ]);
        });
    }
};
