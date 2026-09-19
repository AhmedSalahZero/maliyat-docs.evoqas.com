<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderOtherCostLine extends Model
{
    protected $fillable = ['production_order_id', 'category_id', 'description', 'amount'];

    // decimal(12,2) in the database (2026_09_19_000005 migration).
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
