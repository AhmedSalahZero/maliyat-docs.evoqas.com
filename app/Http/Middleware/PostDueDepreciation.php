<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\DepreciationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PostDueDepreciation
//
//  Posts any depreciation a company owes on the first page it opens
//  each day, so the numbers are right at the moment somebody reads
//  them.
//
//  Why this exists: depreciation used to run only from the server's
//  cron. If that one crontab line was missing there was no error and
//  no warning — depreciation silently never posted, and the mistake
//  surfaced months later as wrong figures. Driving it from the app
//  removes the server configuration from the picture entirely.
//
//  A dormant company posts nothing, which is correct: nobody is
//  reading its figures, and the instant somebody does, every missed
//  month is caught up before the page renders.
//
//  Note it runs AFTER the response is sent (terminate), so the
//  catch-up never delays the page. The date guard means the whole
//  thing is skipped on all but the first request of the day.
// ══════════════════════════════════════════════════════════════════
class PostDueDepreciation
{
    public function __construct(
        private readonly DepreciationService $depreciation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Laravel calls this once the response is already on its way to
     * the browser, so the user waits for none of it.
     */
    public function terminate(Request $request, Response $response): void
    {
        $company = $this->companyDueToday($request);

        if (! $company) {
            return;
        }

        // Claim the day FIRST. If the catch-up then throws, the
        // company is not re-attempted on every single page load for
        // the rest of the day — it retries tomorrow, and any months
        // that did post are already saved against their own asset.
        $company->forceFill(['depreciation_checked_on' => Carbon::today()->toDateString()])->save();

        try {
            $this->depreciation->catchUpCompany($company);
        } catch (\Throwable $e) {
            // Depreciation is backend bookkeeping. A failure here
            // must never take down the page the user asked for.
            Log::error('Depreciation catch-up failed', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * The viewer's company, but only if it has not been checked yet
     * today — otherwise null, and nothing runs.
     */
    private function companyDueToday(Request $request): ?Company
    {
        if (! $this->shouldConsider($request)) {
            return null;
        }

        $company = $request->user()?->company;

        // A super_admin has no company of their own; there is
        // nothing to depreciate on their behalf.
        if (! $company instanceof Company) {
            return null;
        }

        // Already loaded by HandleInertiaRequests, so this costs
        // nothing on the 99% of requests that stop here.
        if ($company->depreciation_checked_on?->isSameDay(Carbon::today())) {
            return null;
        }

        return $company;
    }

    /**
     * Real reads only: assets, service-worker files and speculative
     * prefetches are filtered out, and nothing runs for a guest.
     *
     * Unlike TrackDailyUserAccess this does NOT exclude JSON — a
     * data endpoint is exactly a moment when figures are about to be
     * read, so it should find them up to date. The once-a-day guard
     * means including them costs nothing.
     */
    private function shouldConsider(Request $request): bool
    {
        if (! $request->user()) {
            return false;
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->header('Purpose') === 'prefetch') {
            return false;
        }

        if ($request->is(
            'build/*',
            'images/*',
            'storage/*',
            'vendor/*',
            'favicon.ico',
            'manifest.webmanifest',
            'sw.js',
            'offline.html',
        )) {
            return false;
        }

        return ! str_starts_with($request->path(), 'workbox-');
    }
}
