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
            if (auth()->check() && auth()->user()->company_id) {
                $builder->where(
                    $builder->getModel()->getTable().'.company_id',
                    auth()->user()->company_id
                );
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
