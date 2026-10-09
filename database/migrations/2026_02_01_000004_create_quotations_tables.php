<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->string('currency', 3)->default('VND');

            // Các cột tổng hợp do QuotationWorkflow::recalculate() ghi, không nhập tay.
            $table->decimal('subtotal', 15, 2)->default(0);        // sau chiết khấu, trước thuế
            $table->decimal('discount', 15, 2)->default(0);        // tổng chiết khấu các hạng mục
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('estimated_cost', 15, 2)->default(0);
            $table->decimal('estimated_profit', 15, 2)->default(0);
            $table->decimal('estimated_margin', 8, 2)->default(0); // %, giới hạn ±999999.99

            $table->string('status', 30)->default('draft');
            $table->boolean('requires_director_approval')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // PostgreSQL không tự tạo index cho khoá ngoại.
            $table->index('customer_id');
            $table->index('opportunity_id');
            $table->index('created_by');
            $table->index('status');
            $table->index('quotation_date');
            $table->index('valid_until');
            $table->index('created_at');
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);        // số tiền chiết khấu của hạng mục
            $table->decimal('tax_rate', 5, 2)->default(0);         // %
            $table->decimal('subtotal', 15, 2)->default(0);        // quantity × unit_price − discount
            $table->decimal('estimated_cost', 15, 2)->default(0);  // giá vốn ước tính cho MỘT đơn vị
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
