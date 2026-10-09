<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bộ đếm sinh mã chứng từ: CUS-2026-0001, QT-2026-0001...
     * Mỗi (prefix, year) một dòng; year = 0 cho mã không theo năm.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 20);
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
