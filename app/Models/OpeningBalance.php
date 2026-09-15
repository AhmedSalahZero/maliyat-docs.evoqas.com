<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OpeningBalance
//  Location: app/Models/OpeningBalance.php
//
//  Holds only the cash + bank half of a company's opening balance,
//  plus the draft/posted lock. See the migration's doc comment for
//  why customer/supplier/inventory/equipment opening lines are
//  ordinary Sale/Expense/InventoryPurchase/EquipmentPurchase rows
//  instead of living here.
// ══════════════════════════════════════════════════════════════════
class OpeningBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'opening_date', 'cash_amount', 'bank_amount',
        'status', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'opening_date' => 'date',
        'cash_amount'  => 'decimal:2',
        'bank_amount'  => 'decimal:2',
        'posted_at'    => 'datetime',
    ];

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
