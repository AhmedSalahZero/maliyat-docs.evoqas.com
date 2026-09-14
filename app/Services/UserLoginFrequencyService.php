<?php

namespace App\Services;

use App\Enums\LoginActivityType;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Support\Facades\Date;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UserLoginFrequencyService
//  Location: app/Services/UserLoginFrequencyService.php
//
//  Single source of truth for "how often does this user actually use
//  the app" — used by the admin user list and the member's own
//  activity panel (see LoginFrequencyWidgets.vue / useLoginStats.js).
//
//  Writes two things:
//    1. App\Models\User        → login_count, last_login_at, last_activity_at
//    2. user_login_activities  → one immutable row per event (see
//       LoginActivityType: 'login' for explicit sign-ins, one per
//       calendar day for 'daily_access' visits)
//
//  Called from exactly two places — keep it that way, don't scatter
//  activity-recording calls elsewhere:
//    - App\Listeners\RecordSuccessfulLogin  → recordExplicitLogin()
//    - App\Http\Middleware\TrackDailyUserAccess → recordDailyAccessIfNeeded()
// ══════════════════════════════════════════════════════════════════
class UserLoginFrequencyService
{
    /**
     * Record an explicit login (the user submitted the login form / was
     * authenticated via a fresh session). Always writes a new 'login' row
     * — a user can log in more than once a day — and also makes sure
     * today's 'daily_access' row exists, so the middleware doesn't need
     * to write a second row later in the same request cycle's day.
     */
    public function recordExplicitLogin(User $user, string $source = 'web'): void
    {
        $now   = Date::now();
        $today = $now->toDateString();

        UserLoginActivity::create([
            'user_id'       => $user->id,
            'login_at'      => $now,
            'activity_date' => $today,
            'type'          => LoginActivityType::Login,
            'source'        => $source,
        ]);

        $this->ensureDailyAccessRow($user, $now, $today, $source);

        $user->forceFill([
            'login_count'      => $user->login_count + 1,
            'last_login_at'    => $now,
            'last_activity_at' => $now,
        ])->save();
    }

    /**
     * Record the first authenticated page view of the current calendar
     * day. Idempotent — safe to call on every request; only writes once
     * per user per day. Does NOT touch login_count (that's only for
     * explicit logins), but does keep last_activity_at fresh.
     */
    public function recordDailyAccessIfNeeded(User $user, string $source = 'web'): void
    {
        $now   = Date::now();
        $today = $now->toDateString();

        $created = $this->ensureDailyAccessRow($user, $now, $today, $source);

        // Keep last_activity_at current even on days that already have
        // a daily_access row — it should reflect "most recent visit",
        // not just "most recent new day".
        if (! $created && $user->last_activity_at?->isSameDay($now)) {
            return;
        }

        $user->forceFill(['last_activity_at' => $now])->save();
    }

    /**
     * Insert today's daily_access row if it doesn't already exist.
     * Returns true if a new row was created, false if one already existed.
     */
    private function ensureDailyAccessRow(User $user, $now, string $today, string $source): bool
    {
        $activity = UserLoginActivity::firstOrCreate([
            'user_id'       => $user->id,
            'activity_date' => $today,
            'type'          => LoginActivityType::DailyAccess,
        ], [
            'login_at' => $now,
            'source'   => $source,
        ]);

        return $activity->wasRecentlyCreated;
    }
}
