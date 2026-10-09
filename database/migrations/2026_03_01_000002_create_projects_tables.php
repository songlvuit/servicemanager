<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('project_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('contract_value', 15, 2)->default(0);   // doanh thu trước thuế của dự án

            // Các cột dưới đây do ProjectFinancials::recalculate() ghi, không nhập tay.
            $table->decimal('budget', 15, 2)->default(0);           // Σ ngân sách kế hoạch
            $table->decimal('committed_cost', 15, 2)->default(0);   // đã cam kết (gồm cả phần đã chi)
            $table->decimal('actual_cost', 15, 2)->default(0);      // đã chi thực tế
            $table->decimal('labor_cost', 15, 2)->default(0);       // trong actual_cost: nhân sự
            $table->decimal('supplier_cost', 15, 2)->default(0);    // trong actual_cost: nhà cung cấp
            $table->decimal('equipment_cost', 15, 2)->default(0);   // trong actual_cost: thiết bị
            $table->decimal('other_cost', 15, 2)->default(0);       // trong actual_cost: còn lại
            $table->decimal('expected_profit', 15, 2)->default(0);  // contract_value − budget
            $table->decimal('actual_profit', 15, 2)->default(0);    // contract_value − actual_cost
            $table->decimal('profit_margin', 8, 2)->default(0);     // actual_profit / contract_value × 100
            $table->decimal('invoiced_amount', 15, 2)->default(0);  // Σ hóa đơn đã phát hành (gồm thuế)
            $table->decimal('collected_amount', 15, 2)->default(0); // Σ tiền đã thu
            $table->unsignedTinyInteger('progress')->default(0);    // % công việc hoàn thành

            $table->string('status', 30)->default('planning');
            $table->string('drive_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_id');
            $table->index('customer_id');
            $table->index('project_manager_id');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('created_at');
        });

        // Nhân sự tham gia dự án.
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable(); // vai trò trong dự án: Đạo diễn, Quay phim...
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('description')->nullable();
            $table->decimal('planned_amount', 15, 2)->default(0);
            // Ba cột dưới do ProjectFinancials ghi từ chi phí, đơn mua hàng và chấm công.
            $table->decimal('committed_amount', 15, 2)->default(0);
            $table->decimal('actual_amount', 15, 2)->default(0);
            $table->decimal('variance', 15, 2)->default(0);         // planned_amount − actual_amount
            $table->timestamps();

            // Mỗi dự án có một dòng ngân sách cho mỗi nhóm chi phí.
            $table->unique(['project_id', 'category']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('todo');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('estimated_hours', 8, 2)->default(0);
            $table->decimal('actual_hours', 8, 2)->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'status', 'sort_order']);
            $table->index('assignee_id');
            $table->index('parent_id');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('budget_items');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
