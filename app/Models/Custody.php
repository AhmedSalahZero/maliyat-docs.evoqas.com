<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Custody extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'holder_id', 'amount', 'method', 'payment_channel_id', 'given_at',
        'settled', 'settlement_date', 'settlement_total',
        'leftover_returned', 'extra_reimbursed', 'created_by',
    ];

    protected $casts = [
        'given_at' => 'date',
        'settlement_date' => 'date',
        'settled' => 'boolean',
    ];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'holder_id');
    }

    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    public function settlementLines(): HasMany
    {
        return $this->hasMany(CustodySettlementLine::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Settle this custody: record what it was spent on, mark it
     * settled, and compute the leftover/extra difference. Does not
     * itself write to the payments table — the caller records the
     * original hand-out and any leftover/extra cash movement there,
     * same as the prototype's cash-flow logic.
     */
    public function settle(array $lines): void
    {
        $this->settlementLines()->delete();

        foreach ($lines as $line) {
            $this->settlementLines()->create([
                'description' => $line['description'] ?? null,
                'category_id' => $line['category_id'] ?? null,
                'amount' => $line['amount'],
            ]);
        }

        $total = collect($lines)->sum('amount');

        $this->update([
            'settled' => true,
            'settlement_date' => now()->toDateString(),
            'settlement_total' => $total,
            'leftover_returned' => max($this->amount - $total, 0),
            'extra_reimbursed' => max($total - $this->amount, 0),
        ]);
    }
}
