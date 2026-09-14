<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — User Login Activities
//
//  Backs App\Models\UserLoginActivity, which is read/written by
//  App\Services\UserLoginFrequencyService. Two kinds of rows are
//  written here (see App\Enums\LoginActivityType):
//
//    - 'login'        → one row per explicit login (multiple per day
//                        are allowed — every sign-in is recorded).
//    - 'daily_access'  → at most ONE row per user per calendar day,
//                        written on the first authenticated page view
//                        of that day (see TrackDailyUserAccess).
//
//  Used to power the login-frequency widgets on the admin user list
//  and the member's own "my activity" panel (see LoginFrequencyWidgets.vue
//  and the useLoginStats composable).
//
//  This table has no `updated_at` column — rows are immutable once
//  written (append-only activity log), matching UserLoginActivity's
//  `public $timestamps = false`.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->timestamp('login_at');     // exact moment recorded
            $table->date('activity_date');     // calendar day (server tz) — used to dedupe daily_access
            $table->string('type', 20);        // login | daily_access (see LoginActivityType)
            $table->string('source', 20)->nullable(); // web | api | mobile, etc.

            $table->timestamp('created_at')->useCurrent();

            // Fast lookups for "how many days was this user active" queries,
            // and lets the service check "has today already been recorded?"
            // without a full table scan.
            $table->index(['user_id', 'activity_date', 'type'], 'user_login_activities_user_day_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_activities');
    }
};
