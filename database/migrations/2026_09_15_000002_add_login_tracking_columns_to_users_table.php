<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Login tracking columns on Users
//
//  App\Models\User already lists login_count, last_login_at and
//  last_activity_at in $fillable / $casts (copied over from
//  InPractice), but no migration ever created these columns here.
//  UserLoginFrequencyService keeps them in sync:
//    - last_login_at   → updated on every explicit login
//    - last_activity_at → updated on every first-page-of-the-day visit
//    - login_count      → incremented on every explicit login
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('login_count')->default(0)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('login_count');
            $table->timestamp('last_activity_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_count', 'last_login_at', 'last_activity_at']);
        });
    }
};
