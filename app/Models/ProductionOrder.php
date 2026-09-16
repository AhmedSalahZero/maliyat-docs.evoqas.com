<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ProductionOrder ("Day Production")
//  Location: app/Models/ProductionOrder.php
//
//  One production run: item made + qty + the materials/labor/other
//  costs that went into it. See ProductionOrderService for how
//  total_cost/unit_cost are computed and stock is moved, and
//  JournalService::postProductionOrder() for the ledger entry.
// ══════════════════════════════════════════════════════════════════
class ProductionOrder extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'item_id', 'date', 'qty_produced',
        'material_cost', 'labor_cost', 'other_cost_total', 'total_cost', 'unit_cost',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function materialLines(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterialLine::class);
    }

    public function otherCostLines(): HasMany
    {
        return $this->hasMany(ProductionOrderOtherCostLine::class);
    }

    /**
     * Sum of labor_cost across every Production Order for one
     * company within one calendar month — the "applied" side of
     * the labor variance (see JournalService::postProductionLaborExpense()).
     */
    public static function totalLaborForMonth(int $companyId, string $dateInMonth): float
    {
        return (float) static::query()
            ->where('company_id', $companyId)
            ->whereYear('date', substr($dateInMonth, 0, 4))
            ->whereMonth('date', substr($dateInMonth, 5, 2))
            ->sum('labor_cost');
    }
}
