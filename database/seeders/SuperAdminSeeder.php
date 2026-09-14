<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SuperAdminSeeder
//
//  Creates the one platform super_admin account (no company_id —
//  a super_admin isn't scoped to any single company; see
//  BelongsToCompany and EnsureAdmin). Safe to re-run: updates the
//  existing row instead of duplicating it.
//
//  ⚠️ Change this password immediately after first deploy.
// ══════════════════════════════════════════════════════════════════
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@maliyatdocs.app'],
            [
                'name'              => 'Maliyat Docs Admin',
                'password'          => 'ChangeMe@2026!',
                'role'              => UserRole::SuperAdmin->value,
                'company_id'        => null,
                'language'          => 'en',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Super admin ready: admin@maliyatdocs.app / ChangeMe@2026!');
        $this->command->warn('   ⚠️  Change this password after first login.');
    }
}
