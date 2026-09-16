<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Users: theme column + nullable nickname
//
//  1. `theme` (light|dark) — read/written by the preferences.theme
//     route and shared to every page by HandleInertiaRequests, but
//     no migration ever created the column (another InPractice gap:
//     that app didn't have a users.theme column either, it inferred
//     theme from onboarding a different way).
//
//  2. `nickname` was InPractice's public/anonymous display name
//     (required + unique, shown in forum posts). MaliyatDocs has no
//     public forum, so registration no longer collects one. Rather
//     than fabricate a fake value for every signup, this makes the
//     column nullable.
//
//     FIX (QA audit, Sep 2026): this used to run a raw MySQL-only
//     `ALTER TABLE ... MODIFY` statement, guarded behind
//     `DB::getDriverName() === 'mysql'`. On every other driver —
//     including sqlite, which is this app's own default connection
//     (see config/database.php) — that guard silently did nothing,
//     so `nickname` stayed NOT NULL while RegisterService::register()
//     never sets it. Net effect: creating a new company's first user
//     (i.e. public sign-up) would fail with a NOT NULL constraint
//     violation on sqlite, and on any other non-MySQL database.
//     Laravel's native `->nullable()->change()` performs the
//     equivalent alteration on every supported driver (MySQL,
//     PostgreSQL, SQLite, SQL Server) without needing doctrine/dbal
//     or a driver-specific branch, so it replaces the raw statement
//     here.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('light')->after('language');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });

        // Not reverted: forcing `nickname` back to NOT NULL here
        // would fail outright on any row created since this
        // migration ran (registration no longer supplies one), so
        // rolling back only undoes the column this migration added.
    }
};
