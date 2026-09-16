<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Users: drop InPractice leftover columns
//
//  This app was built on top of an earlier, unrelated product
//  ("InPractice" — a professional-community platform with forums,
//  freelance job matching, CVs, and surveys). Its `users` table
//  carried columns that made sense there but have no meaning in a
//  bookkeeping company account, and — confirmed against a live
//  export of this app's own production database — every one of
//  them sits empty/at its default value on every real user row,
//  and nothing in the working application reads or writes any of
//  them (see the class-doc comment on App\Models\User for the
//  per-column trail of evidence).
//
//  Dropped here:
//    nickname          — InPractice's public/anonymous forum handle
//    profession        — e.g. "Financial Analyst"
//    experience_level  — student/fresh/junior/mid/senior
//    sector            — professional industry background
//    bio               — free-text personal biography
//    avatar            — profile photo path (the app's real avatar
//                        UI is initials-from-name, see AppLayout.vue,
//                        and never reads this column)
//    show_real_name    — "show my real name publicly?" toggle,
//                        meaningless with no public profile to show
//    notify_jobs
//    notify_freelance
//    notify_forum      \  notification preferences for features
//    notify_surveys     > (forum, freelance, surveys, job posts)
//    notify_documents  /  that don't exist in this application
//
//  Kept: `language` and `theme` — both real, live preferences (see
//  their extensive use across the app, unlike the columns above).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The unique index on `nickname` has to go before the
            // column itself, or the DROP COLUMN below fails on
            // MySQL/MariaDB with the index still attached to it.
            $table->dropUnique('users_nickname_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nickname',
                'profession',
                'experience_level',
                'sector',
                'bio',
                'avatar',
                'show_real_name',
                'notify_jobs',
                'notify_freelance',
                'notify_forum',
                'notify_surveys',
                'notify_documents',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname')->nullable()->unique()->after('name');
            $table->string('profession')->nullable();
            $table->enum('experience_level', ['student', 'fresh', 'junior', 'mid', 'senior'])
                ->default('fresh');
            $table->string('sector')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->boolean('show_real_name')->default(false);
            $table->boolean('notify_jobs')->default(true);
            $table->boolean('notify_freelance')->default(true);
            $table->boolean('notify_forum')->default(true);
            $table->boolean('notify_surveys')->default(true);
            $table->boolean('notify_documents')->default(true);
        });
    }
};
