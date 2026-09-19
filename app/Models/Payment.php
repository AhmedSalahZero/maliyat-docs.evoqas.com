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
        'is_opening_balance',
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
        'amount' => 'decimal:2',
    ];

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
