<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Widen users.role
//
//  users.role was created by the InPractice base migration as
//  ENUM('admin','member'). MaliyatDocs's own code — App\Enums\UserRole,
//  and User::isSuperAdmin()/isCompanyAdmin()/isEmployee() — reads and
//  writes 'super_admin' | 'company_admin' | 'employee' instead.
//  Left as-is, the very first attempt to create a company admin or
//  employee fails with a database-level "Data truncated for column
//  'role'" error, because MySQL enforces the original enum list.
//
//  Fix: widen the column to a plain VARCHAR. Role values are already
//  validated in PHP by App\Enums\UserRole — we don't need the database
//  to also enforce the list, and a plain string sidesteps needing the
//  doctrine/dbal package (which isn't installed) just to redefine an
//  enum via Blueprint::change().
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'employee'");
        }

        // Backfill any leftover InPractice values so existing rows stay valid.
        DB::table('users')->where('role', 'admin')->update(['role' => 'super_admin']);
        DB::table('users')->where('role', 'member')->update(['role' => 'employee']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','member') NOT NULL DEFAULT 'member'");
        }
    }
};
