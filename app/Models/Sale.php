<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Sale extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_id', 'sales_channel_id', 'date', 'subtotal',
        'vat_rate', 'vat_amount', 'amount', 'due_date', 'created_by',
        'is_opening_balance',
    ];

    // Every money column here is decimal(12,2) in the database (see
    // the 2026_09_14_000007 migration). Casting them explicitly —
    // rather than letting Eloquent hand back whatever raw string or
    // float the PDO driver happens to return — is what lets every
    // other file in the app treat ->amount, ->subtotal, etc. as a
    // known, consistent PHP float instead of quietly depending on
    // driver behavior. See FinancialRules::AMOUNT_TOLERANCE for the
    // one rounding tolerance every "is this equal/paid" check uses.
    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'is_opening_balance' => 'boolean',
        'subtotal' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
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
}
