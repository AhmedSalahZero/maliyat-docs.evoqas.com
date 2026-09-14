<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Email Verification Codes Table
//
//  App\Services\Auth\EmailVerificationService has always referenced
//  this table and App\Models\EmailVerificationCode, but no migration
//  ever created it here (another gap carried over from the
//  InPractice copy). With AUTH_EMAIL_VERIFICATION_ENABLED defaulting
//  to true, that made registration fail outright — the service is
//  called the moment a user signs up.
//
//  The plain code is never stored; only a bcrypt hash of it.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code_hash');

            // How many wrong guesses have been made against this code.
            // The service locks the code once it reaches
            // auth_verification.max_attempts.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // The service's hot path is "newest unverified code for
            // this user" — see issueAndSend(), verify(), hasActiveCode().
            $table->index(['user_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
