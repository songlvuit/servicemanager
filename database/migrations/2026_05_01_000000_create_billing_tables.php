<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biên bản nghiệm thu (mục 29).
        Schema::create('acceptances', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->date('acceptance_date');
            $table->decimal('amount', 15, 2)->default(0); // giá trị nghiệm thu, trước thuế
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
            $table->index('status');
            $table->index('acceptance_date');
            $table->index('created_at');
        });

        // Hóa đơn (mục 30).
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('acceptance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('subtotal', 15, 2)->default(0);         // trước thuế — doanh thu
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);              // subtotal × tax_rate %
            $table->decimal('total', 15, 2)->default(0);            // subtotal + tax
            $table->decimal('paid_amount', 15, 2)->default(0);      // Σ thanh toán đã ghi nhận
            $table->decimal('remaining_amount', 15, 2)->default(0); // total − paid_amount
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('contract_id');
            $table->index('project_id');
            $table->index('acceptance_id');
            $table->index('status');
            $table->index('invoice_date');
            $table->index('due_date');
            $table->index('created_at');
        });

        // Thanh toán của khách hàng cho hóa đơn (mục 31). Không sửa, không xoá.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('customer_id');
            $table->index('payment_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('acceptances');
    }
};
