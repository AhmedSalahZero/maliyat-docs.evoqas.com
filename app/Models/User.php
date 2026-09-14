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
    protected $fillable = [
        'name',
        'nickname',
        'email',
        'password',
        'role',
        'company_id',
        'created_by',
        'profession',
        'experience_level',
        'sector',
        'bio',
        'avatar',
        'language',
        'theme',
        'show_real_name',
        'notify_jobs',
        'notify_freelance',
        'notify_forum',
        'notify_surveys',
        'notify_documents',
        'is_active',
        'last_login_at',
        'last_activity_at',
        'login_count',
        'phone',
        'highly_rated_solutions_count',  // Case Practitioner badge track
        'highly_rated_replies_count',    // Field Advisor badge track
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
        'show_real_name'                => 'boolean',
        'notify_jobs'                   => 'boolean',
        'notify_freelance'              => 'boolean',
        'notify_forum'                  => 'boolean',
        'notify_surveys'                => 'boolean',
        'notify_documents'              => 'boolean',
        'is_active'                     => 'boolean',
        'highly_rated_solutions_count'  => 'integer',
        'highly_rated_replies_count'    => 'integer',
    ];

    // ── Helpers ───────────────────────────────────────────────

    public function getPublicNameAttribute(): string
    {
        return $this->show_real_name ? $this->name : $this->nickname;
    }

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

        // Read the flag straight from the table rather than through
        // $this->company. That relation may already be cached on this
        // instance — HandleInertiaRequests loads it earlier in the
        // same request — and a cached copy can be stale wherever the
        // resolved user outlives one request, which is exactly what
        // happens under a persistent worker (Octane) or the session
        // guard's per-instance user cache. An authorization check
        // must not be answered from a cache it doesn't control, so
        // this costs one primary-key lookup instead.
        $companyIsActive = Company::query()
            ->whereKey($this->company_id)
            ->value('is_active');

        if ($companyIsActive !== null && ! $companyIsActive) {
            return 'errors.company_suspended';
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
