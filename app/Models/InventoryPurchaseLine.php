<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPurchaseLine extends Model
{
    protected $fillable = [
        'inventory_purchase_id', 'item_id', 'qty', 'uom',
        'qty_per_uom', 'base_unit_name', 'unit_price', 'line_total',
    ];

    // decimal(12,2) in the database (2026_09_14_000011 migration).
    protected $casts = [
        'qty' => 'decimal:2',
        'qty_per_uom' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function inventoryPurchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
