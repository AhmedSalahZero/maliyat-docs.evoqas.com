<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Unit of Measure on Sale Lines
//
//  Closes the gap where a Sale had no unit choice at all: an item
//  bought as "100 Carton = 1,000 kg" could only ever be SOLD in
//  whatever unit `qty` silently meant (base units), so a sale of
//  "5" was always 5 kg, never 5 cartons. Mirrors exactly what
//  inventory_purchase_lines already does —
//    qty            → how many of the chosen unit were sold
//    uom            → the chosen unit's name (e.g. "Carton")
//    qty_per_uom    → how many base units ONE of that unit equals
//    base_unit_name → the base unit's name (e.g. "kg")
//  Stock leaving the item, and Cost of Goods Sold, are always
//  computed from qty * qty_per_uom (base units) — see Item::
//  totalSoldBase() and MovingAverageCostingService — never from qty
//  alone, exactly the same rule purchases already follow.
//
//  Snapshotted PER LINE, not just read from the item, for the same
//  reason purchase lines snapshot it: so a past sale's stock/cost
//  math never shifts if the item's unit definition changes later.
//
//  Backfill: every sale line that already exists was recorded back
//  when `qty` had no unit — which meant it was always treated as
//  base units. Setting qty_per_uom = 1 and uom = base_unit_name
//  (falling back to the item's own base_unit_name, or 'unit') keeps
//  every historical qty * qty_per_uom identical to the qty already
//  on the row, so no past stock figure or Cost of Goods Sold number
//  changes because of this migration.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->string('uom')->nullable()->after('qty');
            $table->decimal('qty_per_uom', 12, 2)->default(1)->after('uom');
            $table->string('base_unit_name')->nullable()->after('qty_per_uom');
        });

        DB::table('sale_lines')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->update([
                'sale_lines.qty_per_uom'    => 1,
                'sale_lines.base_unit_name' => DB::raw("COALESCE(items.base_unit_name, 'unit')"),
                'sale_lines.uom'            => DB::raw("COALESCE(items.base_unit_name, 'unit')"),
            ]);

        // Free-text/service lines have no item to read a base unit
        // name from — harmless defaults, never used in any stock or
        // costing math (see Item's saleLines() callers, all of
        // which only ever query lines with a real item_id).
        DB::table('sale_lines')->whereNull('item_id')->update([
            'qty_per_uom'    => 1,
            'base_unit_name' => 'unit',
            'uom'            => 'unit',
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn(['uom', 'qty_per_uom', 'base_unit_name']);
        });
    }
};
