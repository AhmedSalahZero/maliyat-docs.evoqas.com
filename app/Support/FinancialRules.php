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
//  from 2000 up to today: comfortably wide enough for real
//  back-entry, narrow enough that a typo is caught while the user
//  is still looking at the form.
//
//  A transaction date is "when this happened" — a sale, an expense,
//  a purchase — and none of those are ever legitimately in the
//  future in this app: there's no cheque/post-dated payment method
//  here (payments are cash/bank/instapay/wallet/visa, all settled
//  the moment they're recorded), and "pay later" is handled by the
//  separate due_date field below, which already has its own much
//  wider window (up to 10 years ahead) for exactly that case. So
//  date() itself is capped at today, full stop — see the QA
//  follow-up (Sep 2026) that tightened this from "today + 1 year".
// ══════════════════════════════════════════════════════════════════
class FinancialRules
{
    /** The largest value any single money field may hold. */
    public const MAX_AMOUNT = 999999999.99;

    /** The largest quantity a single line may carry. */
    public const MAX_QTY = 1000000;

    /**
     * The ONE tolerance used everywhere two money amounts are
     * compared for "close enough to call equal" — half a cent.
     *
     * Every money column in this schema is decimal(12,2): the
     * smallest real unit anyone can enter is one cent (0.01). This
     * constant is strictly smaller than that on purpose — it exists
     * only to absorb floating-point noise introduced when PHP does
     * arithmetic on values read out of those columns (e.g.
     * 19.999999999999996 instead of 20.0), never to forgive an
     * actual cent of difference.
     *
     * (QA audit, Sep 2026: this used to be redefined independently
     * in eight different files, and two different values had
     * drifted in — 0.004 in most places, 0.01 in JournalService's
     * balance guard and in ReportDataService's "is_balanced" flags.
     * That meant an entry could pass the guard that creates it but
     * fail a stricter check elsewhere. Every one of those call
     * sites now points at this single constant, so they can no
     * longer disagree with each other.)
     */
    public const AMOUNT_TOLERANCE = 0.004;

    /** Nothing in a company's books predates this. */
    public const MIN_DATE = '2000-01-01';

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

    /**
     * A transaction date can never be in the future — see the
     * class doc comment for why (no post-dated payment methods
     * exist in this app; "pay later" is a separate field with its
     * own separate window).
     *
     * Evaluated explicitly in Cairo time, not the app's configured
     * default (UTC, see config/app.php) — every user of this app is
     * an Egyptian business, so "today" has to mean today in Cairo,
     * not today in Greenwich. Without this, there's a ~2-3 hour
     * window every night (after midnight in Cairo, before midnight
     * in UTC) where the server would still think it's yesterday and
     * would wrongly reject someone correctly entering today's date.
     */
    public static function latestAllowedDate(): string
    {
        return Carbon::today('Africa/Cairo')->toDateString();
    }

    /**
     * True if two money amounts are the same once floating-point
     * noise is accounted for. The single "are these equal" check
     * for balanced journal entries, matched totals, etc.
     */
    public static function amountsEqual(float $a, float $b): bool
    {
        return abs($a - $b) <= self::AMOUNT_TOLERANCE;
    }

    /**
     * True if $actual has reached (or is within float-noise of)
     * $expected — the single "is this fully paid / fully covered"
     * check, so $actual landing a hair below $expected purely from
     * float math never reads as "still short".
     */
    public static function amountAtLeast(float $actual, float $expected): bool
    {
        return $actual >= $expected - self::AMOUNT_TOLERANCE;
    }
}
