<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Master Seeder
//
//  Run with:  php artisan db:seed
//  Or fresh:  php artisan migrate:fresh --seed
//
//  Only seeds the one super_admin account — company data (customers,
//  vendors, sales, etc.) is created by real companies signing up
//  and using the app, not by a seeder.
// ══════════════════════════════════════════════════════════════════
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
        ]);
    }
}
