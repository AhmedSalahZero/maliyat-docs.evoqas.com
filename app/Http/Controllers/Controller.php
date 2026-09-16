<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — base Controller
//
//  Carries the two things every destructive action in the app
//  shares, so they read the same way everywhere and cannot be left
//  out of a new one by accident.
// ══════════════════════════════════════════════════════════════════
abstract class Controller
{
    /**
     * Deleting a financial record is a company-admin action.
     *
     * Every other route under /app is open to any company user,
     * which is right: employees are there to enter the books. Delete
     * is the exception, and the reason is what delete actually does
     * here — removing a sale also removes the payments recorded
     * against it, so the cash that came in disappears alongside the
     * invoice. The rows are gone for good (there is no soft delete),
     * which is why every destroy() also calls logDeletion() below —
     * gone from the working tables is fine, gone with no trace at
     * all is not.
     *
     * Editing is deliberately NOT behind this. An edit leaves a full
     * reversal trail in the general ledger and touches no payments,
     * so a mistake there is visible and recoverable; a delete is
     * neither.
     *
     * Called at the top of each destroy(), rather than relied on
     * from a form request, because three of the delete routes take
     * no request body and therefore have no request class to put it
     * in — see e.g. SaleController::destroy().
     */
    protected function authorizeDelete(): void
    {
        abort_unless(
            auth()->user()?->isCompanyAdmin() === true,
            403,
            __('errors.delete_requires_admin')
        );
    }

    /**
     * Record what a financial record looked like the instant before
     * it's deleted — who deleted it, and what it contained.
     *
     * QA audit (Sep 2026) finding: destroy() permanently erased the
     * row with nothing anywhere saying who did it or what was in it.
     * For a bookkeeping app that's a real gap — there was no way to
     * answer "who deleted this invoice, and what did it say?" after
     * the fact, whether the delete was a mistake or something worse.
     *
     * Call this BEFORE the actual delete, inside the same
     * DB::transaction() the delete already runs in, so the two
     * either both happen or neither does — a delete can never
     * succeed while leaving no trace behind.
     *
     * $summary should be a short, human-readable line (e.g. "Sale
     * #482 — Acme Trading — 4,500.00 EGP") — see each destroy() for
     * the exact wording it uses. $extra is for anything worth
     * capturing that the model itself doesn't own, e.g. a sale's own
     * line items, since those are deleted separately and won't be on
     * $model->toArray() by the time anyone reads this log back.
     */
    protected function logDeletion(Model $model, string $summary, array $extra = []): void
    {
        \App\Support\DeletionLogger::log($model, $summary, $extra);
    }
}
