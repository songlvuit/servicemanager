<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->decimal('contract_value', 15, 2)->default(0); // giá trị trước thuế (doanh thu)
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_value', 15, 2)->default(0);    // contract_value + tax_amount
            $table->date('sign_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('payment_terms')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // PostgreSQL không tự tạo index cho khoá ngoại.
            $table->index('customer_id');
            $table->index('quotation_id');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
