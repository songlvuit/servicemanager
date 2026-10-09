<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('estimated_value', 15, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->foreignId('sales_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('new');
            $table->timestamps();

            // PostgreSQL không tự tạo index cho khoá ngoại.
            $table->index('customer_id');
            $table->index('sales_owner_id');
            $table->index('status');
            $table->index('expected_close_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
