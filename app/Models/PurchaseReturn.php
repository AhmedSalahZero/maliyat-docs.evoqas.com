<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PurchaseReturn
//  Goods we sent back to a supplier after a purchase. Mirror image
//  of SaleReturn. See PurchaseReturnService.
// ══════════════════════════════════════════════════════════════════
class PurchaseReturn extends Model
{
    use HasFactory, BelongsToCompany;

    /** refund_method value meaning "keep it as credit with the supplier". */
    public const CREDIT = 'credit';

    protected $fillable = [
        'company_id', 'inventory_purchase_id', 'vendor_id', 'date',
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

    public function inventoryPurchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /** The cash/bank refund received from the supplier, when paid as money. */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function isCreditRefund(): bool
    {
        return $this->refund_method === self::CREDIT && (float) $this->refund_amount > 0;
    }
}
