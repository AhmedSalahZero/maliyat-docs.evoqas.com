<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — FinancialRules
//  Location: app/Support/FinancialRules.php
//
//  The bounds every money field and every transaction date in the
//  app is validated against, defined once. Same idea as
//  PasswordRules: the rule lives in one place so it cannot drift
//  between the twenty-odd request classes that need it.
//
//  Why a ceiling at all — every money column in this schema is
//  decimal(12,2), which stops at 9,999,999,999.99. Validation used
//  to check only `numeric` and `min`, so a mistyped figure went
//  straight to MySQL and came back as a 500 error page ("Numeric
//  value out of range"), with no field highlighted and nothing
//  saying which number was wrong. MAX_AMOUNT sits an order of
//  magnitude below the column ceiling so that totals built by
//  summing lines still fit comfortably, and so the user gets a
//  normal "this is too large" message on the field itself.
//
//  Why a date window — a bookkeeping date typo ('2099' for '2026',
//  a stray digit) used to be accepted silently and then sat in the
//  ledger skewing every report that spanned it. Dates are allowed
//  from 2000 up to a year ahead: comfortably wide enough for real
//  back-entry and for genuinely post-dated paperwork, narrow enough
//  that a typo is caught while the user is still looking at the form.
// ══════════════════════════════════════════════════════════════════
class FinancialRules
{
    /** The largest value any single money field may hold. */
    public const MAX_AMOUNT = 999999999.99;

    /** The largest quantity a single line may carry. */
    public const MAX_QTY = 1000000;

    /** Nothing in a company's books predates this. */
    public const MIN_DATE = '2000-01-01';

    /** How far ahead a transaction may be dated. */
    public const MAX_YEARS_AHEAD = 1;

    /**
     * A money field: positive, and within the column's range.
     *
     * @param  float  $min  0 where a zero is meaningful (a free line,
     *                      a blank opening-balance row), 0.01 where
     *                      the field only exists because money moved.
     * @return list<string>
     */
    public static function amount(float $min = 0.01): array
    {
        return ['numeric', 'min:'.$min, 'max:'.self::MAX_AMOUNT];
    }

    /**
     * A quantity field — same reasoning, different ceiling.
     *
     * @return list<string>
     */
    public static function qty(float $min = 0.01): array
    {
        return ['numeric', 'min:'.$min, 'max:'.self::MAX_QTY];
    }

    /**
     * A transaction date: a real date inside the window above.
     *
     * @return list<string>
     */
    public static function date(): array
    {
        return [
            'date',
            'after_or_equal:'.self::MIN_DATE,
            'before_or_equal:'.self::latestAllowedDate(),
        ];
    }

    /**
     * A due date — the same window, but reaching further forward,
     * since an installment plan legitimately schedules payments
     * years out. Kept separate from date() so tightening one does
     * not silently tighten the other.
     *
     * @return list<string>
     */
    public static function dueDate(): array
    {
        return [
            'date',
            'after_or_equal:'.self::MIN_DATE,
            'before_or_equal:'.self::latestAllowedDueDate(),
        ];
    }

    public static function latestAllowedDueDate(): string
    {
        return Carbon::today()->addYears(10)->toDateString();
    }

    public static function latestAllowedDate(): string
    {
        return Carbon::today()->addYears(self::MAX_YEARS_AHEAD)->toDateString();
    }
}
