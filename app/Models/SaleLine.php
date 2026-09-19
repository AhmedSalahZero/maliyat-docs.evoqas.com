<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    protected $fillable = ['sale_id', 'item_id', 'qty', 'unit_price', 'line_total', 'unit_cost'];

    // decimal(12,2) in the database (2026_09_14_000008 migration).
    // unit_cost is decimal(14,4) (2026_09_27_000002 migration) — the
    // moving-average cost this line was actually priced at; see
    // MovingAverageCostingService. Null until priced, and for a
    // line with no item.
    protected $casts = [
        'qty' => 'decimal:2',
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
}
