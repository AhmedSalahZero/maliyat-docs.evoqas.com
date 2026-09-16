<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    // ── Fillable ───────────────────────────────────────────────
    protected $fillable = [
        'name',
        'name_ar',
        'currency',
        'created_by',
        'is_active',
        'trial_ends_at',
        'expiry_notified_at',
        'business_types',
    ];

    // ── Casts ─────────────────────────────────────────────────
    protected $casts = [
        'is_active'               => 'boolean',
        'trial_ends_at'           => 'datetime',
        'expiry_notified_at'      => 'datetime',
        'depreciation_checked_on' => 'date',
        'business_types'          => 'array',
    ];

    // ── Business type (Service / Trading / Production) ───────────
    //
    //  Multi-select, stored as a JSON array of these strings. A
    //  company with none set (older rows, or a stray null) is
    //  treated as ['trading'] — exactly how the app behaved for
    //  everyone before this existed, so nothing breaks for existing
    //  customers. See the 2026_09_19_000001 migration's backfill,
    //  which does the same thing at the database level; this getter
    //  is the safety net for any row that somehow still has null.
    public const BUSINESS_TYPES = ['service', 'trading', 'production'];

    public function businessTypes(): array
    {
        $types = $this->business_types;

        return is_array($types) && count($types) > 0 ? $types : ['trading'];
    }

    public function hasBusinessType(string $type): bool
    {
        return in_array($type, $this->businessTypes(), true);
    }

    /**
     * Service-only companies have nothing to buy, stock, or sell as
     * goods — the whole Inventory area (Items, Inventory Purchases,
     * Inventory Statement) is hidden for them on the frontend.
     */
    public function needsInventory(): bool
    {
        return $this->hasBusinessType('trading') || $this->hasBusinessType('production');
    }

    /**
     * Every company starts its free trial the moment it is created —
     * public sign-up (RegisterService), an admin onboarding one by
     * hand (Admin\CompanyController), a seeder, a future import.
     * Doing it here rather than at each call site means no path can
     * accidentally create a company with unlimited free access.
     *
     * An explicit trial_ends_at passed in is respected, so a paid
     * company can still be created with null (never expires).
     */
    protected static function booted(): void
    {
        static::creating(function (self $company) {
            if (! array_key_exists('trial_ends_at', $company->getAttributes())) {
                $company->trial_ends_at = now()->addMonthsNoOverflow(
                    (int) config('subscription.trial_months', 2)
                );
            }
        });
    }

    // ── Trial / subscription window ──────────────────────────────
    //
    //  A company can be switched off two different ways and the app
    //  keeps them apart, because what the customer has to do differs:
    //  is_active = false is an administrative suspension (contact
    //  support), a lapsed trial_ends_at is a billing matter (renew).
    //  Both are enforced in User::accessDenialReason().
    //
    //  trial_ends_at = null means "never expires" — that is how a
    //  company which has actually paid is marked.

    /**
     * Start (or restart) the free trial from now.
     */
    public function startTrial(?int $months = null): static
    {
        $months ??= (int) config('subscription.trial_months', 2);

        $this->forceFill([
            'trial_ends_at'      => now()->addMonthsNoOverflow($months),
            'expiry_notified_at' => null,
        ])->save();

        return $this;
    }

    /**
     * The trial window has closed — the company can no longer sign in.
     */
    public function hasLapsed(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * Whole days left before access stops, or null when the company
     * has no expiry at all. Never negative — a lapsed trial reads 0.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->trial_ends_at->startOfDay(), false));
    }

    /**
     * Inside the warning window — drives both the in-app banner and
     * the reminder email.
     */
    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null
            && ! $this->hasLapsed()
            && $days <= (int) config('subscription.notify_days_before', 7);
    }

    // ── Relationships ─────────────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function inventoryPurchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function equipmentPurchases(): HasMany
    {
        return $this->hasMany(EquipmentPurchase::class);
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
