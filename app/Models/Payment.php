<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'payable_type', 'payable_id',
        'date', 'amount', 'method', 'payment_channel_id', 'direction',
        'customer_id', 'vendor_id', 'category_id', 'note',
        'is_opening_balance', 'is_customer_credit',
    ];

    // decimal(12,2) in the database (2026_09_14_000015 migration).
    // Cast explicitly so every ->payments->sum('amount') call across
    // the app (paidAmount() on Sale/Expense/InventoryPurchase/
    // EquipmentPurchase, the dashboard, the reports) is summing a
    // known, consistent value rather than whatever the DB driver
    // happens to hand back.
    protected $casts = [
        'date' => 'date',
        'is_opening_balance' => 'boolean',
        'is_customer_credit' => 'boolean',
        'amount' => 'decimal:2',
    ];

    /**
     * The screen that owns this payment when it is NOT an ordinary
     * payment the Payments screen may correct or remove (audit M2):
     *
     *   'opening_balance' — the starting cash/bank figure, created by
     *                       the Opening Balance screen and posted
     *                       against Owner's Equity;
     *   'custody'         — the money handed to / returned by an
     *                       employee, part of the custody's own entry;
     *   'owner'           — an owner's capital or withdrawal.
     *
     * Their ledger entries are made by those screens, not by
     * PaymentController, so editing or deleting them from Payments
     * would either fail or re-book them to the wrong accounts.
     * Returns null for an ordinary payment.
     */
    public function managedBy(): ?string
    {
        if ($this->is_opening_balance && $this->payable_type === null) {
            return 'opening_balance';
        }

        return match ($this->payable_type) {
            null, Sale::class, Expense::class, InventoryPurchase::class, EquipmentPurchase::class => null,
            Custody::class          => 'custody',
            OwnerTransaction::class => 'owner',
            default                 => 'other',
        };
    }

    /**
     * The message explaining where to change this payment instead.
     * Keys are written out in full so the translation check can see
     * every one of them.
     */
    public function managedElsewhereMessage(): string
    {
        return match ($this->managedBy()) {
            'opening_balance' => __('errors.payment_managed_elsewhere.opening_balance'),
            'custody'         => __('errors.payment_managed_elsewhere.custody'),
            'owner'           => __('errors.payment_managed_elsewhere.owner'),
            default           => __('errors.payment_managed_elsewhere.other'),
        };
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeCashIn($query)
    {
        return $query->where('direction', 'in');
    }

    public function scopeCashOut($query)
    {
        return $query->where('direction', 'out');
    }
}
