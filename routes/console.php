<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ══════════════════════════════════════════════════════════════════
//  Scheduled tasks
//
//  NOTE — none of this runs until the server's own cron calls
//  Laravel's scheduler once a minute:
//      * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
//  Without that single crontab line the schedule below is inert,
//  with no error and no warning.
//
//  That silent-failure risk is why DEPRECIATION IS NO LONGER HERE.
//  It is now driven by the app itself — see PostDueDepreciation,
//  registered in bootstrap/app.php — which catches a company up on
//  the first page it opens each day and needs no server setup at
//  all. `php artisan depreciation:run` still exists for manual
//  sweeps, and is safe to add back to a crontab if you have one:
//  both paths share DepreciationService and its per-company lock,
//  so neither can post the same month twice.
// ══════════════════════════════════════════════════════════════════

// Warn company admins whose free trial is about to end.
//
// Daily, and early — before the working day starts — so the customer
// has the whole day to act on it. The command's own
// expiry_notified_at guard is what stops a daily schedule turning
// into a daily email for the entire final week.
Schedule::command('subscriptions:notify-expiring')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();
