<?php

namespace App\Support;

use App\Models\DeletionLog;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DeletionLogger
//  Location: app/Support/DeletionLogger.php
//
//  The actual write behind Controller::logDeletion(). Pulled out to
//  its own class (rather than left as a Controller method only)
//  because ProductionOrderService also deletes a financial record
//  and is a plain service, not a controller — it has no
//  authorizeDelete()/logDeletion() to inherit. Both now call the
//  same single implementation, so the log format can't drift
//  between "deleted from a controller" and "deleted from a
//  service".
// ══════════════════════════════════════════════════════════════════
class DeletionLogger
{
    public static function log(Model $model, string $summary, array $extra = []): void
    {
        $companyId = $model->company_id ?? auth()->user()?->company_id;

        if (! $companyId) {
            // Nothing sensible to attribute this to — fail safe by
            // not logging rather than writing a row no company-side
            // screen could ever find.
            return;
        }

        DeletionLog::create([
            'company_id' => $companyId,
            'user_id'    => auth()->id(),
            'model_type' => $model::class,
            'model_id'   => $model->getKey(),
            'summary'    => $summary,
            'payload'    => array_merge($model->toArray(), $extra ? ['_related' => $extra] : []),
            'deleted_at' => now(),
        ]);
    }
}
