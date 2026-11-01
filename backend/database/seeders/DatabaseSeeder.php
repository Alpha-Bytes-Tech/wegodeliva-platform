<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data for every environment. Safe to run repeatedly (upserts).
 * Roles are created by their migration.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            VendorTypeSeeder::class,
            ServiceCategorySeeder::class,
            CitySeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
