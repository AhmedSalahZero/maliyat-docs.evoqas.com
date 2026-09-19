<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EquipmentPurchase extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'vendor_id', 'category_id', 'name',
        'qty', 'unit_price', 'amount', 'date', 'due_date', 'created_by',
        'useful_life_years', 'accumulated_depreciation', 'last_depreciated_through',
        'is_opening_balance',
    ];

    // decimal(12,2) in the database (2026_09_14_000012 and
    // 2026_09_16_000011 migrations) — cast explicitly for the same
    // reason as Sale::$casts.
    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'last_depreciated_through' => 'date',
        'is_opening_balance' => 'boolean',
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function installments(): MorphMany
    {
        return $this->morphMany(Installment::class, 'payable')->orderBy('sequence');
    }

    public function paidAmount(): float
    {
        // Sum the already-loaded collection when the caller eager
        // loaded payments (every index screen does). Going through
        // the relation's query builder unconditionally ignored that
        // eager load and fired one SUM per row — and because
        // balance() and isPaid() both call through here, that was
        // three queries per row, not one.
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum('amount');
        }

        return (float) $this->payments()->sum('amount');
    }

    public function balance(): float
    {
        return (float) $this->amount - $this->paidAmount();
    }

    public function isPaid(): bool
    {
        return $this->balance() <= FinancialRules::AMOUNT_TOLERANCE;
    }

    /**
     * Straight-line monthly depreciation — entirely a backend/
     * accounting concept, never surfaced in the UI. Rounded to
     * cents; the depreciation command caps the final month so the
     * asset never depreciates below zero / past its own cost.
     */
    public function monthlyDepreciationAmount(): float
    {
        if ($this->useful_life_years <= 0) {
            return 0.0;
        }

        return round((float) $this->amount / ($this->useful_life_years * 12), 2);
    }

    public function remainingDepreciableAmount(): float
    {
        return max(0.0, (float) $this->amount - (float) $this->accumulated_depreciation);
    }

    public function isFullyDepreciated(): bool
    {
        return $this->remainingDepreciableAmount() <= FinancialRules::AMOUNT_TOLERANCE;
    }
}
