<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // ── Fillable ───────────────────────────────────────────────
    //
    // Cleaned up (QA audit, Sep 2026): this used to also list
    // nickname, profession, experience_level, sector, bio, avatar,
    // show_real_name, notify_jobs, notify_freelance, notify_forum,
    // notify_surveys, notify_documents, highly_rated_solutions_count,
    // and highly_rated_replies_count — all leftover from the earlier,
    // unrelated "InPractice" product this app was built on top of.
    // Checked against a live export of this app's own production
    // database: every one of those columns sat empty/default on
    // every real user row, and nothing in the working application
    // ever read or wrote any of them. The database columns
    // themselves were dropped in
    // 2026_09_16_000001_drop_inpractice_leftover_columns_from_users_table.php;
    // the two "highly_rated_*" fields were never real columns at
    // all (dead entries left in this array with no matching column
    // in any migration), so nothing further was needed for those
    // beyond removing them from this list.
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'created_by',
        'language',
        'theme',
        'is_active',
        'last_login_at',
        'last_activity_at',
        'login_count',
        'phone',
    ];

    // ── Hidden ────────────────────────────────────────────────
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // ── Casts ─────────────────────────────────────────────────
    protected $casts = [
        'email_verified_at'             => 'datetime',
        'last_login_at'                 => 'datetime',
        'last_activity_at'              => 'datetime',
        'login_count'                   => 'integer',
        'password'                      => 'hashed',
        'theme'                         => 'string',
        'is_active'                     => 'boolean',
    ];

    // ── Helpers ───────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === \App\Enums\UserRole::SuperAdmin->value;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === \App\Enums\UserRole::CompanyAdmin->value;
    }

    public function isEmployee(): bool
    {
        return $this->role === \App\Enums\UserRole::Employee->value;
    }

    /**
     * A super_admin can manage every company; a company_admin can only
     * create/manage users within their own company.
     */
    public function canManageUsers(): bool
    {
        return $this->isSuperAdmin() || $this->isCompanyAdmin();
    }

    /**
     * Why this user may not use the app right now, as a translation
     * key — or null when access is allowed.
     *
     * Single source of truth for both entry points: LoginRequest
     * (checked once, at sign-in) and EnsureMember (checked on every
     * request, so a company deactivated mid-session is locked out
     * immediately rather than at their next login). Keeping the rule
     * in one place is what stops those two from drifting apart.
     *
     * Order matters — the most specific reason wins, so a suspended
     * user inside a suspended company is told about their own
     * account rather than the company's.
     */
    public function accessDenialReason(): ?string
    {
        if (! $this->is_active) {
            return 'errors.account_suspended';
        }

        // A super_admin has no company row to check — they exist
        // above the tenant boundary.
        if ($this->isSuperAdmin()) {
            return null;
        }

        // Read straight from the table rather than through
        // $this->company. That relation may already be cached on this
        // instance — HandleInertiaRequests loads it earlier in the
        // same request — and a cached copy can be stale wherever the
        // resolved user outlives one request, which is exactly what
        // happens under a persistent worker (Octane) or the session
        // guard's per-instance user cache. An authorization check
        // must not be answered from a cache it doesn't control, so
        // this costs one primary-key lookup instead.
        $company = Company::query()
            ->whereKey($this->company_id)
            ->first(['is_active', 'trial_ends_at']);

        // No company behind a non-super_admin account means the
        // account is ORPHANED, and an orphan must not be let in.
        //
        // This used to `return null` — "no company, nothing to check,
        // carry on" — and that was a cross-tenant hole, not a
        // harmless gap. users.company_id is nullOnDelete (see
        // 2026_09_14_000002_add_company_fields_to_users_table.php),
        // so deleting a company leaves its admins and employees
        // behind with company_id = null. And BelongsToCompany only
        // applies its global scope `if (auth()->user()->company_id)`
        // — a null company_id does not scope the query to nothing,
        // it switches the tenant filter OFF ENTIRELY. The orphan
        // signed in and read every company on the platform.
        //
        // Verified before the fix: an admin whose company row was
        // deleted signed in normally and listed another tenant's
        // customers. (/app/dashboard answered 403, so it was not
        // fully usable — but "the UI mostly refuses" is not the
        // boundary this application claims to enforce.)
        //
        // Admin\CompanyController::destroy() deletes a company's
        // users explicitly, so the supported delete path does not
        // create orphans. This covers every other way a row can go:
        // an older build, a manual SQL delete, a restored backup, a
        // failed migration.
        if (! $company) {
            return 'errors.account_orphaned';
        }

        // An administrative suspension outranks a billing one: if an
        // admin has switched the company off, saying "renew your
        // subscription" would send the customer down the wrong path.
        if (! $company->is_active) {
            return 'errors.company_suspended';
        }

        if ($company->hasLapsed()) {
            return 'errors.subscription_expired';
        }

        return null;
    }

    /**
     * When AUTH_EMAIL_VERIFICATION_ENABLED=false, treat every user as verified.
     */
    public function hasVerifiedEmail(): bool
    {
        if (! config('auth_verification.enabled')) {
            return true;
        }

        return $this->email_verified_at !== null;
    }

    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationService::class)->issueAndSend($this);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // ── Relationships ─────────────────────────────────────────

    public function emailVerificationCodes(): HasMany
    {
        return $this->hasMany(EmailVerificationCode::class);
    }

    // ── Relationships (Maliyat Docs) ────────────────────────────

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Users this account created (a super_admin's company_admins,
     * or a company_admin's employees).
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    // ── Relationships ─────────────────────────────────────────

    public function loginActivities(): HasMany
    {
        return $this->hasMany(UserLoginActivity::class);
    }
}
