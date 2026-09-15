<?php

namespace App\Services;

use App\Models\Company;
use App\Models\EquipmentPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DepreciationService
//
//  Straight-line monthly depreciation, posted as a catch-up rather
//  than on a timetable: for each asset it works out every calendar
//  month that has fully closed since its last posting and files one
//  journal entry per month.
//
//  That design is what lets depreciation run from a web request
//  instead of from cron. Because the work is derived from each
//  asset's own last_depreciated_through watermark, it does not
//  matter WHEN this runs or how often — only that it runs at some
//  point before somebody reads the numbers.
//
//  Two callers, one body:
//    • PostDueDepreciation  — the first page a company opens each
//      day (the normal path; no server configuration needed).
//    • depreciation:run     — the same work for every company at
//      once, for manual runs and for anyone who does have cron.
//
//  Both go through catchUpCompany(), so they cannot collide: the
//  lock below is per company and is held by whichever gets there
//  first.
// ══════════════════════════════════════════════════════════════════
class DepreciationService
{
    /** How long one company's catch-up may hold its lock. */
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * Post everything due for one company.
     *
     * The lock is not an optimisation — it is what stops two
     * simultaneous requests both reading accumulated_depreciation,
     * both posting, and leaving duplicate entries in the ledger.
     * Financial data, so it is not optional.
     *
     * block(0) rather than a wait: if another request already holds
     * the lock the work is already being done, so this caller
     * returns immediately instead of holding a page open.
     *
     * @return int  entries posted, or -1 if another run held the lock
     */
    public function catchUpCompany(Company $company, ?Carbon $today = null): int
    {
        $lock = Cache::lock("depreciation:company:{$company->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return -1;
        }

        try {
            return $this->postDueFor($company, $today ?? Carbon::today());
        } finally {
            $lock->release();
        }
    }

    /**
     * Every company that still has something to depreciate.
     *
     * Runs unscoped on purpose — the command has no logged-in user,
     * so BelongsToCompany's global scope is inert and this is the
     * only caller that legitimately reaches across companies.
     *
     * @return int  entries posted across all companies
     */
    public function catchUpAllCompanies(?Carbon $today = null): int
    {
        $today = $today ?? Carbon::today();
        $total = 0;

        Company::query()
            ->whereIn('id', $this->companyIdsWithAssetsStillDepreciating())
            ->chunkById(50, function ($companies) use ($today, &$total) {
                foreach ($companies as $company) {
                    $posted = $this->catchUpCompany($company, $today);

                    // -1 means somebody else was mid-run; not an error.
                    $total += max(0, $posted);

                    $company->forceFill(['depreciation_checked_on' => $today->toDateString()])->save();
                }
            });

        return $total;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function companyIdsWithAssetsStillDepreciating(): \Illuminate\Support\Collection
    {
        return $this->stillDepreciating()->distinct()->pluck('company_id');
    }

    /**
     * Assets with a useful life that have not yet reached their cost.
     *
     * withoutGlobalScope so the caller decides the company, rather
     * than whoever happens to be logged in — the middleware passes a
     * Company explicitly and the command means all of them.
     */
    private function stillDepreciating(): \Illuminate\Database\Eloquent\Builder
    {
        return EquipmentPurchase::query()
            ->withoutGlobalScope('company')
            ->where('useful_life_years', '>', 0)
            ->whereColumn('accumulated_depreciation', '<', 'amount');
    }

    private function postDueFor(Company $company, Carbon $today): int
    {
        $posted = 0;

        $this->stillDepreciating()
            ->where('company_id', $company->id)
            ->chunkById(100, function ($purchases) use ($today, &$posted) {
                foreach ($purchases as $purchase) {
                    $posted += $this->catchUpAsset($purchase, $today);
                }
            });

        return $posted;
    }

    /**
     * Post depreciation for every calendar month between this
     * asset's last posting and today that has fully closed.
     *
     * The first period runs from the purchase month; after that the
     * watermark takes over. The final month is capped so the asset
     * never depreciates past its own cost.
     */
    public function catchUpAsset(EquipmentPurchase $purchase, Carbon $today): int
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
