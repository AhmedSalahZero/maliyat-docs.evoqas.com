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

    public function inventoryPurchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
