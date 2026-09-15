<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Users Table
//
//  Roles:
//    admin → Platform manager (Mahmoud's team)
//    member → Registered professional / student
//
//  Privacy:
//    nickname → shown publicly in forum, cases, documents
//    real name, phone, email → hidden until user consents
//
//  Hub membership is stored in user_hubs table (many-to-many)
//
//  Languages:
//    en → English (LTR)
//    ar → Arabic  (RTL)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ── Identity ───────────────────────────────────────
            $table->string('name');                               // real full name (private)
            $table->string('nickname')->unique();                 // public display name
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // ── Role ───────────────────────────────────────────
            $table->enum('role', ['admin', 'member'])->default('member');

            // ── Professional Info ──────────────────────────────
            $table->string('profession')->nullable();             // e.g. "Financial Analyst"
            $table->enum('experience_level', [
                'student',           // still studying
                'fresh',             // 0-1 years
                'junior',            // 1-3 years
                'mid',               // 3-6 years
                'senior',            // 6+ years
            ])->default('fresh');
            $table->string('sector')->nullable();                 // their industry background
            $table->text('bio')->nullable();                      // short personal description

            // ── Avatar ────────────────────────────────────────
            $table->string('avatar')->nullable();                 // profile photo path

            // ── Preferences ───────────────────────────────────
            $table->enum('language', ['en', 'ar'])->default('en');
            $table->boolean('show_real_name')->default(false);    // show real name publicly?

            // ── Notification Preferences ───────────────────────
            $table->boolean('notify_jobs')->default(true);
            $table->boolean('notify_freelance')->default(true);
            $table->boolean('notify_forum')->default(true);
            $table->boolean('notify_surveys')->default(true);
            $table->boolean('notify_documents')->default(true);

            // ── Status ────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Contact (private) ─────────────────────────────
            $table->string('phone')->nullable();

            $table->timestamps();
        });

        // ── Password Reset ─────────────────────────────────────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ── Sessions ───────────────────────────────────────────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
