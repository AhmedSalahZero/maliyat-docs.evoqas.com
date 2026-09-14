<?php

namespace App\Console\Commands;

use App\Models\EquipmentPurchase;
use App\Services\JournalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RunDepreciation
//  Location: app/Console/Commands/RunDepreciation.php
//
//  `php artisan depreciation:run` — normally never run by hand,
//  scheduled in routes/console.php instead.
//
//  For each equipment/vehicle purchase not yet fully depreciated,
//  posts one journal entry per calendar month that has fully
//  elapsed since the last posting (or since the purchase date, for
//  the first run) — see EquipmentPurchase::monthlyDepreciationAmount().
//  The final month is capped so the asset never depreciates past
//  its own cost.
//
//  IMPORTANT — this needs your server's system cron pointed at
//  Laravel's scheduler for it to ever actually run:
//      * * * * * php artisan schedule:run >> /dev/null 2>&1
//  Without that one cron line, this command exists but nothing
//  ever calls it.
// ══════════════════════════════════════════════════════════════════
class RunDepreciation extends Command
{
    protected $signature = 'depreciation:run';

    protected $description = 'Post straight-line monthly depreciation for every equipment/vehicle purchase still within its useful life (invisible backend accounting only)';

    public function __construct(
        private readonly JournalService $journal,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::today();
        $entriesPosted = 0;

        EquipmentPurchase::query()
            ->where('useful_life_years', '>', 0)
            ->whereColumn('accumulated_depreciation', '<', 'amount')
            ->chunkById(100, function ($purchases) use ($today, &$entriesPosted) {
                foreach ($purchases as $purchase) {
                    $entriesPosted += $this->catchUp($purchase, $today);
                }
            });

        $this->info("Depreciation: posted {$entriesPosted} entr" . ($entriesPosted === 1 ? 'y' : 'ies') . '.');

        return self::SUCCESS;
    }

    /**
     * Post depreciation for every calendar month between this
     * asset's last posting and today that has fully closed.
     */
    private function catchUp(EquipmentPurchase $purchase, Carbon $today): int
    {
        $monthly = $purchase->monthlyDepreciationAmount();
        if ($monthly <= 0) {
            return 0;
        }

        $periodEnd = $purchase->last_depreciated_through
            ? Carbon::parse($purchase->last_depreciated_through)->addMonthNoOverflow()->endOfMonth()
            : Carbon::parse($purchase->date)->endOfMonth();

        $posted = 0;

        while ($periodEnd->lessThanOrEqualTo($today) && $purchase->remainingDepreciableAmount() > 0.004) {
            $amount = min($monthly, $purchase->remainingDepreciableAmount());

            $this->journal->postDepreciation($purchase, $amount, $periodEnd->toDateString());

            $purchase->accumulated_depreciation = round((float) $purchase->accumulated_depreciation + $amount, 2);
            $purchase->last_depreciated_through = $periodEnd->toDateString();
            $purchase->save();

            $posted++;
            $periodEnd = $periodEnd->copy()->addMonthNoOverflow()->endOfMonth();
        }

        return $posted;
    }
}
