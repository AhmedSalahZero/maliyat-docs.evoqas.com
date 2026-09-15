<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — base Controller
//
//  Carries the one authorization check that every destructive action
//  in the app shares, so it reads the same way in all of them and
//  cannot be left out of a new one by accident.
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
     * invoice. The rows are gone for good (there is no soft delete)
     * and nothing records who removed them.
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
}
