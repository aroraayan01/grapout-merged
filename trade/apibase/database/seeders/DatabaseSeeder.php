<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DefaultCategorySeeder::class,
            PlanSeeder::class,
            DemoSeeder::class,
            // Sample Hot Leads rows, until the real export is imported with
            // `grap:import`. Only ever adds rows marked 'sample'.
            GrapLeadSeeder::class,
        ]);
    }
}
