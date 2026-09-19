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

    // qty_produced/material_cost/labor_cost/other_cost_total/total_cost
    // are decimal(12,2); unit_cost is decimal(12,4) — it genuinely
    // carries an extra two digits of precision in the database (see
    // the 2026_09_19_000003 migration) so that dividing a total cost
    // across a large production run doesn't lose accuracy per unit,
    // so it's cast to 4 decimal places to match, not truncated to 2.
    protected $casts = [
        'date' => 'date',
        'qty_produced' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'other_cost_total' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'unit_cost' => 'decimal:4',
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
