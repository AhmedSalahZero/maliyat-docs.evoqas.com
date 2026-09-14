<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'uom', 'qty_per_uom', 'base_unit_name'];

    protected $casts = [
        'qty_per_uom' => 'decimal:2',
    ];

    public function purchaseLines(): HasMany
    {
        return $this->hasMany(InventoryPurchaseLine::class);
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /**
     * Total base units purchased across all inventory purchases.
     */
    public function totalPurchasedBase(): float
    {
        return (float) $this->purchaseLines()
            ->selectRaw('SUM(qty * qty_per_uom) as total')
            ->value('total') ?: 0;
    }

    /**
     * Total base units sold.
     */
    public function totalSoldBase(): float
    {
        return (float) $this->saleLines()->sum('qty');
    }

    public function currentStock(): float
    {
        return $this->totalPurchasedBase() - $this->totalSoldBase();
    }

    /**
     * Weighted average cost per base unit across every purchase
     * (not just the most recent) — matches the prototype's
     * "Average purchase cost" figure.
     */
    public function averagePurchaseCost(): ?float
    {
        $totalCost = (float) $this->purchaseLines()->sum('line_total');
        $totalBase = $this->totalPurchasedBase();

        return $totalBase > 0 ? $totalCost / $totalBase : null;
    }
}
