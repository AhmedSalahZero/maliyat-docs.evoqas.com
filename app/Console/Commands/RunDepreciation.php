<?php

namespace App\Console\Commands;

use App\Services\DepreciationService;
use Illuminate\Console\Command;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RunDepreciation
//  Location: app/Console/Commands/RunDepreciation.php
//
//  `php artisan depreciation:run` — posts straight-line monthly
//  depreciation for every company at once.
//
//  This is NO LONGER the primary path and is no longer scheduled.
//  Depreciation is now driven by the app itself: PostDueDepreciation
//  catches a company up on the first page it opens each day, which
//  means it works with no server configuration at all. See
//  routes/console.php for why that change was made.
//
//  The command is kept for two reasons:
//    • running a catch-up by hand, e.g. right after a data import;
//    • sweeping companies nobody has logged into, for anyone who
//      does have cron available and wants the belt and braces.
//
//  Adding it back to a crontab is safe: it shares DepreciationService
//  with the middleware, and the per-company lock in there means the
//  two can never post the same month twice.
// ══════════════════════════════════════════════════════════════════
class RunDepreciation extends Command
{
    protected $signature = 'depreciation:run';

    protected $description = 'Post straight-line monthly depreciation for every equipment/vehicle purchase still within its useful life (invisible backend accounting only)';

    public function __construct(
        private readonly DepreciationService $depreciation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $entriesPosted = $this->depreciation->catchUpAllCompanies();

        $this->info("Depreciation: posted {$entriesPosted} entr".($entriesPosted === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
