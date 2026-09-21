<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    protected $fillable = [
        'sale_id', 'item_id', 'qty', 'uom', 'qty_per_uom', 'base_unit_name',
        'unit_price', 'line_total', 'unit_cost',
    ];

    // decimal(12,2) in the database (2026_09_14_000008 migration).
    // unit_cost is decimal(14,4) (2026_09_27_000002 migration) — the
    // moving-average cost this line was actually priced at, PER
    // BASE UNIT; see MovingAverageCostingService. Null until priced,
    // and for a line with no item.
    // uom/qty_per_uom/base_unit_name (2026_09_29_000001 migration) —
    // the unit the customer was actually invoiced in (e.g. "Carton")
    // and how many base units (e.g. "kg") that equals. qty is always
    // in THIS unit, never in base units directly — see baseQty().
    protected $casts = [
        'qty' => 'decimal:2',
        'qty_per_uom' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * This line's quantity converted to base units — the number
     * that actually leaves the item's stock and that Cost of Goods
     * Sold is priced against. qty_per_uom defaults to 1 (guards
     * against a stray null on an old/free-text row) so a line sold
     * in the item's own base unit converts to itself unchanged.
     */
    public function baseQty(): float
    {
        return round((float) $this->qty * ((float) $this->qty_per_uom ?: 1), 2);
    }
}
