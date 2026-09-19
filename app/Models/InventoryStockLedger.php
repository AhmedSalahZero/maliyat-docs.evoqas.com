<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — InventoryStockLedger
//
//  One row per item per calendar day that had any stock movement.
//  Read-only from the outside — always written by
//  MovingAverageCostingService::recalculateItem(), never created or
//  edited directly. See that service, and the migration's doc
//  comment, for the algorithm and why it's day-by-day rather than
//  per-transaction.
// ══════════════════════════════════════════════════════════════════
class InventoryStockLedger extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_stock_ledger';

    protected $fillable = [
        'company_id', 'item_id', 'date',
        'beginning_qty', 'beginning_value',
        'qty_in', 'value_in',
        'average_cost',
        'qty_out', 'value_out',
        'ending_qty', 'ending_value',
    ];

    protected $casts = [
        'date' => 'date',
        'beginning_qty' => 'decimal:2',
        'beginning_value' => 'decimal:2',
        'qty_in' => 'decimal:2',
        'value_in' => 'decimal:2',
        'average_cost' => 'decimal:4',
        'qty_out' => 'decimal:2',
        'value_out' => 'decimal:2',
        'ending_qty' => 'decimal:2',
        'ending_value' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
