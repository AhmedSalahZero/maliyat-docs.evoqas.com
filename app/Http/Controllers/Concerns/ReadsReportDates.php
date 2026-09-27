<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReadsReportDates
//  Location: app/Http/Controllers/Concerns/ReadsReportDates.php
//  Used by ReportController and ReportExportController.
//
//  Report filters arrive in the address bar (?from=&to=), so anyone
//  can type anything there — "2026-13-45", "yesterday", a date
//  pasted in the wrong format. Those used to reach the report code
//  as-is and come back as an error page (audit finding M5).
//
//  Now every report date goes through here first:
//    - a real calendar date written as YYYY-MM-DD is used;
//    - anything else is ignored — the report falls back to its
//      normal range — and a short message says so;
//    - a range typed backwards (From after To) is turned around,
//      also with a message.
//  Nothing here ever throws, so a report always opens.
// ══════════════════════════════════════════════════════════════════
trait ReadsReportDates
{
    /**
     * One date filter from the query string: a valid Y-m-d, or null.
     */
    protected function reportDate(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        if (self::isRealDate($value)) {
            return $value;
        }

        $this->flagReportDateProblem('errors.invalid_report_date');

        return null;
    }

    /**
     * A From/To pair, already checked and in the right order.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function reportRange(Request $request, string $fromKey = 'from', string $toKey = 'to'): array
    {
        $from = $this->reportDate($request, $fromKey);
        $to   = $this->reportDate($request, $toKey);

        if ($from && $to && $from > $to) {
            $this->flagReportDateProblem('errors.report_range_reversed');
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    public static function isRealDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[1] >= 1900 && (int) $m[1] <= 2999;
    }

    /**
     * Show the message on the page being rendered right now (not on
     * the next one), through the usual flash "error" banner.
     */
    private function flagReportDateProblem(string $key): void
    {
        if (app()->bound('session') && request()->hasSession()) {
            request()->session()->now('error', __($key));
        }
    }
}
