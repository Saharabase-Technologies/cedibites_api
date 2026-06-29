<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            BranchSeeder::class,
            MenuCategorySeeder::class,
            MenuTagSeeder::class,
            EmployeeSeeder::class,
            MenuSeeder::class,
            MenuAddOnSeeder::class,
            PromoSeeder::class,
            InventoryCatalogSeeder::class,
        ]);
    }
}
