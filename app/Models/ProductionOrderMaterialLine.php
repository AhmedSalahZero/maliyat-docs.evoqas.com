<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderMaterialLine extends Model
{
    protected $fillable = [
        'production_order_id', 'item_id', 'qty', 'unit_cost_snapshot', 'line_total',
    ];

    // qty/line_total are decimal(12,2); unit_cost_snapshot is
    // decimal(12,4) in the database (2026_09_19_000004 migration) —
    // same reasoning as ProductionOrder::$casts['unit_cost'].
    protected $casts = [
        'qty' => 'decimal:2',
        'unit_cost_snapshot' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
