<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EmailVerificationCode
//
//  One row per OTP issued to a user during sign-up or an
//  unverified login. The code itself is never stored — only its
//  hash — so a leaked database row can't be used to verify an
//  account. See App\Services\Auth\EmailVerificationService for the
//  full issue → verify → cleanup lifecycle.
//
//  Deliberately NOT scoped by company (BelongsToCompany): a code is
//  issued before the user has authenticated at all, so there is no
//  company context to scope against yet.
// ══════════════════════════════════════════════════════════════════
class EmailVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'attempts'    => 'integer',
    ];

    /**
     * Mirrors the column default so a freshly created instance
     * reports 0 attempts in memory, not null — the service compares
     * attempts against max_attempts straight off the returned model.
     */
    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * Never expose the hash — this model is only ever read
     * server-side, but a stray toArray() shouldn't leak it.
     */
    protected $hidden = [
        'code_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A code past its expires_at can no longer be redeemed. The
     * service checks this before comparing hashes so an expired
     * code reports "expired", not "invalid".
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
