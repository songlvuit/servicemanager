<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quy trình duyệt cấu hình được (mục 21, 61).
     *
     * approval_rules   : mỗi dòng là MỘT BƯỚC duyệt áp dụng cho một khoảng giá trị.
     * approval_requests: một lần chạy quy trình cho một chứng từ.
     * approval_steps   : các bước của lần chạy đó (sinh từ approval_rules).
     */
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document_type', 50);              // expense | purchase_request
            $table->decimal('min_amount', 15, 2)->default(0); // áp dụng khi số tiền >= min_amount
            $table->decimal('max_amount', 15, 2)->nullable(); // và <= max_amount (null = không giới hạn)
            $table->string('required_role');                  // tên vai trò phải duyệt bước này
            $table->unsignedInteger('sequence')->default(10); // thứ tự bước, nhỏ duyệt trước
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['document_type', 'is_active', 'sequence']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('document_type', 50);
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status', 20)->default('pending');
            // Vai trò đang được chờ duyệt (bước chờ đầu tiên); null khi quy trình đã kết thúc.
            $table->string('current_role')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_role']);
            $table->index('requester_id');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('approver_role');
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 20)->default('pending');
            $table->timestamp('approved_at')->nullable(); // thời điểm duyệt / từ chối
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['approval_request_id', 'sequence']);
        });

        // Quy trình mặc định (mục 21) — sửa được trong Hệ thống → Quy trình duyệt.
        $now = now();
        $rule = fn (string $name, string $type, int|string $min, ?int $max, string $role, int $sequence): array => [
            'name' => $name,
            'document_type' => $type,
            'min_amount' => $min,
            'max_amount' => $max,
            'required_role' => $role,
            'sequence' => $sequence,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('approval_rules')->insert([
            // Đề nghị chi: PM → Kế toán; từ 5 triệu thêm cấp quản lý; trên 20 triệu thêm Giám đốc.
            $rule('Quản lý công việc duyệt', 'expense', 0, null, 'Project Manager', 10),
            $rule('Kế toán kiểm tra', 'expense', 0, null, 'Accountant', 20),
            $rule('Quản lý duyệt (5 – 20 triệu)', 'expense', 5000000, 20000000, 'Director', 30),
            $rule('Giám đốc duyệt (trên 20 triệu)', 'expense', '20000000.01', null, 'Director', 30),
            // Yêu cầu mua hàng: PM → Kế toán kiểm tra ngân sách; trên 20 triệu thêm Giám đốc.
            $rule('Quản lý công việc duyệt', 'purchase_request', 0, null, 'Project Manager', 10),
            $rule('Kế toán kiểm tra ngân sách', 'purchase_request', 0, null, 'Accountant', 20),
            $rule('Giám đốc duyệt (trên 20 triệu)', 'purchase_request', '20000000.01', null, 'Director', 30),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rules');
    }
};
