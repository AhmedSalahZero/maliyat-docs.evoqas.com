<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
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
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'is_opening_balance' => 'boolean',
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
        return $this->balance() <= 0.004;
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
