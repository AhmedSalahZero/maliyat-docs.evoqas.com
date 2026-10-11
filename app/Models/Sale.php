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
        'vat_rate', 'vat_amount', 'withholding_amount', 'amount', 'returns_applied', 'due_date', 'created_by',
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
        'withholding_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'returns_applied' => 'decimal:2',
    ];

    // IMPORTANT — `amount` is what the customer actually OWES:
    //     subtotal + VAT - withholding_amount.
    // withholding_amount is the Debit Withholding Tax: the part of
    // the invoice the customer keeps back and pays to the tax
    // authority for us. Every balance / payment / open-invoice check
    // in the app reads `amount`, so they all already treat that
    // withheld part as settled. See App\Support\LineTax.

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

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /**
     * Keeps `returns_applied` — the part of all this sale's returns
     * that simply reduced what the customer still owed (the rest was
     * refunded or kept as credit) — equal to the returns on file.
     * Call after a return is recorded or deleted.
     */
    public function refreshReturnsApplied(): void
    {
        $this->forceFill([
            'returns_applied' => round((float) $this->returns()->sum('applied_amount'), 2),
        ])->save();
    }

    public function installments(): MorphMany
    {
        return $this->morphMany(Installment::class, 'payable')->orderBy('sequence');
    }

    /**
     * The invoice total BEFORE the customer's withholding is taken
     * off — what the invoice says on paper (subtotal + VAT). The
     * customer statement shows this as the invoice, then the
     * withholding as its own credit line.
     */
    public function grossAmount(): float
    {
        return round((float) $this->amount + (float) $this->withholding_amount, 2);
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
        // Returns that reduced what is owed (see SaleReturn) come off
        // the amount, exactly like a payment does.
        return (float) $this->amount - (float) $this->returns_applied - $this->paidAmount();
    }

    public function isPaid(): bool
    {
        return $this->balance() <= FinancialRules::AMOUNT_TOLERANCE;
    }
}
