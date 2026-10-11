<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnLine extends Model
{
    protected $fillable = [
        'sale_return_id', 'sale_line_id', 'item_id', 'qty', 'uom', 'qty_per_uom',
        'base_unit_name', 'unit_price', 'line_total', 'vat_amount',
        'withholding_amount', 'unit_cost',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'qty_per_uom' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Returned quantity in base units — what goes back into stock. */
    public function baseQty(): float
    {
        return round((float) $this->qty * ((float) $this->qty_per_uom ?: 1), 4);
    }
}
