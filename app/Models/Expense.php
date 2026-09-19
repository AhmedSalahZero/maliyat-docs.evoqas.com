<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'vendor_id', 'category_id', 'date', 'amount', 'due_date',
        'recurring_id', 'recurring_index', 'recurring_count', 'recurring_frequency',
        'created_by', 'is_opening_balance',
        'is_production_labor', 'production_labor_applied_snapshot',
    ];

    // 'amount' is decimal(12,2) in the database (see the
    // 2026_09_14_000009 migration) — cast explicitly for the same
    // reason as Sale::$casts above. See FinancialRules::AMOUNT_TOLERANCE
    // for the shared rounding tolerance used by isPaid() below.
    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'is_opening_balance' => 'boolean',
        'is_production_labor' => 'boolean',
        'amount' => 'decimal:2',
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

    public function isRecurring(): bool
    {
        return ! empty($this->recurring_id);
    }

    /**
     * The other occurrences in the same recurring series, including this one.
     */
    public function scopeInRecurringSeries($query, string $recurringId)
    {
        return $query->where('recurring_id', $recurringId);
    }
}
