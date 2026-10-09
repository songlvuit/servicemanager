<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sửa cho các database đã tạo bảng notifications với cột data kiểu text:
     * Filament truy vấn data->>'format', PostgreSQL chỉ cho phép trên cột JSON.
     * Database tạo mới đã dùng JSON ngay từ migration trước nên bước này bỏ qua.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $column = DB::selectOne(
            "select data_type from information_schema.columns
             where table_schema = current_schema()
               and table_name = 'notifications'
               and column_name = 'data'"
        );

        if ($column !== null && $column->data_type === 'text') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        }
    }

    public function down(): void
    {
        //
    }
};
