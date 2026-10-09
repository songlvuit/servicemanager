<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cấu hình sửa được trong Hệ thống → Cấu hình: ghi đè giá trị mặc định
        // trong config/*.php (xem App\Support\Settings).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable(); // JSON
            $table->timestamps();
        });

        // Nhật ký thao tác (mục 39). Chỉ thêm, không sửa, không xoá.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();        // tên tại thời điểm thao tác
            $table->string('action', 40);                    // created, updated, status_changed...
            $table->nullableMorphs('auditable');             // bản ghi bị tác động
            $table->string('label')->nullable();             // "Hóa đơn INV-2026-0001"
            $table->text('description')->nullable();         // "Trạng thái: Nháp → Đã phát hành"
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });

        // Mỗi nhắc việc (hợp đồng sắp hết hạn, hóa đơn tới hạn...) chỉ gửi một lần.
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
