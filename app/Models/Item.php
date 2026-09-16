<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Item
//
//  type distinguishes three kinds of item (see the migration that
//  added the column):
//    'trading'      — bought and sold as-is. Default; what every
//                      item was before Production existed.
//    'raw_material' — only ever bought (Inventory Purchase), then
//                      consumed by a Production Order.
//    'product'      — only ever created by a Production Order, then
//                      sold normally like any other item.
//
//  Stock and cost math below is the ONE place both Sales/COGS and
//  the Inventory report read from (see the class doc comments on
//  SaleController and InventoryPurchaseController, and
//  ReportDataService::inventoryStatement()). Production support was
//  added by teaching THESE methods about production — everything
//  that already calls them keeps working unchanged, since $asOf
//  defaults to null (meaning "right now", exactly as before).
// ══════════════════════════════════════════════════════════════════
class Item extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'type', 'uom', 'qty_per_uom', 'base_unit_name'];

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
     * Production Orders where this item is the PRODUCT made
     * (stock coming IN via production, not via purchase).
     */
    public function producedBatches(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /**
     * Lines where this item is a RAW MATERIAL consumed by some
     * Production Order (stock going OUT, alongside sales).
     */
    public function consumedInProduction(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterialLine::class);
    }

    public function scopeTrading($query)
    {
        return $query->where('type', 'trading');
    }

    public function scopeRawMaterial($query)
    {
        return $query->where('type', 'raw_material');
    }

    public function scopeProduct($query)
    {
        return $query->where('type', 'product');
    }

    public function isRawMaterial(): bool
    {
        return $this->type === 'raw_material';
    }

    public function isProduct(): bool
    {
        return $this->type === 'product';
    }

    /**
     * Total base units purchased across all inventory purchases,
     * optionally only those on or before $asOf. For a 'product' item
     * this is normally 0 (products are made, not bought) — see
     * totalProducedBase() for its stock-in.
     */
    public function totalPurchasedBase(?string $asOf = null): float
    {
        return (float) $this->purchaseLines()
            ->when($asOf, fn ($q) => $q->whereHas(
                'inventoryPurchase', fn ($q2) => $q2->where('date', '<=', $asOf)
            ))
            ->selectRaw('SUM(qty * qty_per_uom) as total')
            ->value('total') ?: 0;
    }

    /**
     * Total base units made via Production Orders (only meaningful
     * for a 'product' item), optionally only those on or before
     * $asOf.
     */
    public function totalProducedBase(?string $asOf = null): float
    {
        return (float) $this->producedBatches()
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->sum('qty_produced');
    }

    /**
     * Total base units sold, optionally only those on or before
     * $asOf.
     */
    public function totalSoldBase(?string $asOf = null): float
    {
        return (float) $this->saleLines()
            ->when($asOf, fn ($q) => $q->whereHas(
                'sale', fn ($q2) => $q2->where('date', '<=', $asOf)
            ))
            ->sum('qty');
    }

    /**
     * Total base units consumed as a raw material by Production
     * Orders (only meaningful for a 'raw_material' item), optionally
     * only those on or before $asOf.
     */
    public function totalConsumedInProductionBase(?string $asOf = null): float
    {
        return (float) $this->consumedInProduction()
            ->when($asOf, fn ($q) => $q->whereHas(
                'productionOrder', fn ($q2) => $q2->where('date', '<=', $asOf)
            ))
            ->sum('qty');
    }

    /**
     * Stock on hand. With no argument, this is "right now" — what
     * GuardsRawMaterialStock and every other live caller has always
     * asked for, unchanged. Pass $asOf to ask the same question about
     * a past date instead — this is what lets the Inventory Statement
     * report read stock history from this exact method rather than
     * keeping a second, separately-maintained calculation that can
     * drift out of step with it (see ReportDataService::inventoryStatement()).
     */
    public function currentStock(?string $asOf = null): float
    {
        return $this->totalPurchasedBase($asOf) + $this->totalProducedBase($asOf)
            - $this->totalSoldBase($asOf) - $this->totalConsumedInProductionBase($asOf);
    }

    /**
     * Weighted average cost per base unit across EVERY way this
     * item's stock was ever added — bought (purchase lines) and/or
     * made (production orders) — matches the prototype's "Average
     * purchase cost" figure, just fed from two sources now instead
     * of one. A pure Trading item only ever has purchase lines, so
     * this is unchanged for it. As with currentStock(), pass $asOf to
     * ask what the average was as of a past date rather than right now.
     */
    public function averagePurchaseCost(?string $asOf = null): ?float
    {
        $totalCost = (float) $this->purchaseLines()
                ->when($asOf, fn ($q) => $q->whereHas(
                    'inventoryPurchase', fn ($q2) => $q2->where('date', '<=', $asOf)
                ))
                ->sum('line_total')
            + (float) $this->producedBatches()
                ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
                ->sum('total_cost');
        $totalBase = $this->totalPurchasedBase($asOf) + $this->totalProducedBase($asOf);

        return $totalBase > 0 ? $totalCost / $totalBase : null;
    }
}
