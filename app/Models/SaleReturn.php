<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SaleReturn
//  Goods a customer sent back after a sale. Belongs to the original
//  Sale, has its own date and lines. See the returns migration for
//  what each money column means, and SaleReturnService for how one
//  is recorded.
// ══════════════════════════════════════════════════════════════════
class SaleReturn extends Model
{
    use HasFactory, BelongsToCompany;

    /** refund_method value meaning "keep it as credit on the customer's account". */
    public const CREDIT = 'credit';

    protected $fillable = [
        'company_id', 'sale_id', 'customer_id', 'date',
        'subtotal', 'vat_amount', 'withholding_amount', 'amount',
        'applied_amount', 'refund_amount', 'refund_method', 'payment_channel_id',
        'note', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /** The cash/bank refund payment, when the refund was paid out as money. */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** Refund kept as credit on the customer's account (not paid out). */
    public function isCreditRefund(): bool
    {
        return $this->refund_method === self::CREDIT && (float) $this->refund_amount > 0;
    }

    /** Part of the refund kept as customer credit — 0 unless credit. */
    public function creditAmount(): float
    {
        return $this->isCreditRefund() ? (float) $this->refund_amount : 0.0;
    }
}
