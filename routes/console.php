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
//  Without that single crontab line the schedule below is inert.
// ══════════════════════════════════════════════════════════════════

// Straight-line monthly depreciation for equipment and vehicles.
//
// Run daily rather than monthly on purpose: the command works out
// which whole months have elapsed since each asset's last posting
// and catches up on all of them, so a day the server was down
// simply gets made up the next day. A monthly-only schedule would
// silently skip that period entirely.
//
// withoutOverlapping guards the case where a long catch-up run is
// still going when the next day's run fires.
Schedule::command('depreciation:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();
