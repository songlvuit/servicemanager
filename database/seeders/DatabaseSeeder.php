<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * `php artisan db:seed` — dữ liệu nền, tài khoản mẫu và dữ liệu demo.
     * Các seeder dữ liệu mẫu tự bỏ qua trên production và khi đã có dữ liệu,
     * nên chạy lại nhiều lần đều an toàn. Mỗi phase có một seeder riêng.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DevUserSeeder::class,
            DemoDataSeeder::class,
            DemoContractsSeeder::class,
            DemoOperationsSeeder::class,
            DemoBillingSeeder::class,
            DemoScheduleSeeder::class,
        ]);
    }
}
