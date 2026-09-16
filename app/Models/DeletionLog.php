<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DeletionLog
//  Location: app/Models/DeletionLog.php
//
//  One row per financial record deleted anywhere in the app — see
//  the migration's doc comment for why this exists.
//
//  Deliberately does NOT use BelongsToCompany: this table is a
//  record of what happened, and it must remain fully readable by a
//  platform admin regardless of which company it belongs to. Any
//  company-side screen that lists these must filter by company_id
//  itself, the same way DashboardController's raw queries do.
//
//  No `created_at`/`updated_at` — a log entry is written once, at
//  the moment of deletion, and never changed, so `deleted_at` IS
//  the meaningful timestamp; there's nothing else to track it
//  against. $timestamps = false turns off Eloquent's automatic
//  created_at/updated_at handling entirely — nulling only
//  UPDATED_AT (as an earlier version of this file did) still left
//  Eloquent trying to write created_at on every insert, which
//  doesn't exist as a column here and made every single deletion
//  in the app throw a 500 error. Caught by a real `php artisan
//  test` run against MySQL — sqlite's looser column handling had
//  let it slip through everywhere else.
// ══════════════════════════════════════════════════════════════════
class DeletionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'model_type',
        'model_id',
        'summary',
        'payload',
        'deleted_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'deleted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
