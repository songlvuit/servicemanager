<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Danh mục dịch vụ (mục 13). Tiền lưu DECIMAL(15,2), không dùng float.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('default_unit', 50)->nullable();
            $table->decimal('default_price', 15, 2)->default(0);
            $table->decimal('default_cost', 15, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('category');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
