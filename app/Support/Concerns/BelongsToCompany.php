<?php

namespace App\Support\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — BelongsToCompany
//
//  Applied to every domain model (Sale, Expense, Customer, ...).
//  Two things happen automatically:
//    1. New records get company_id filled from the logged-in user.
//    2. Every query is scoped to that user's company — a
//       company_admin or employee can never see another company's
//       data by accident.
//
//  A super_admin (company_id is null on their user row) is NOT
//  scoped — they see everything, which is what lets them browse
//  across companies from the admin area.
// ══════════════════════════════════════════════════════════════════

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (empty($model->company_id) && auth()->check() && auth()->user()->company_id) {
                $model->company_id = auth()->user()->company_id;
            }
        });

        static::addGlobalScope('company', function (Builder $builder) {
            if (! auth()->check()) {
                return;
            }

            $user = auth()->user();

            if ($user->company_id) {
                $builder->where(
                    $builder->getModel()->getTable().'.company_id',
                    $user->company_id
                );

                return;
            }

            // No company_id. Only a super_admin is legitimately in
            // this state, and they are meant to see across tenants.
            if ($user->isSuperAdmin()) {
                return;
            }

            // Anyone else here is an ORPHAN — a company_admin or
            // employee whose company row is gone (users.company_id is
            // nullOnDelete). The old code tested only
            // `if (auth()->user()->company_id)`, so an orphan fell
            // through to no scope at all and read EVERY company on
            // the platform. "No company" has to mean no rows, not all
            // rows; a filter that fails open is not a filter.
            //
            // User::accessDenialReason() now refuses these accounts
            // at sign-in, which is the real fix. This is the backstop
            // for every path that does not go through a fresh login —
            // a session already open when the company was deleted, a
            // queued job, an artisan command.
            $builder->whereRaw('1 = 0');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
