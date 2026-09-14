<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
//     column nullable — a plain ALTER, no doctrine/dbal required.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('light')->after('language');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY nickname VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY nickname VARCHAR(255) NOT NULL');
        }
    }
};
