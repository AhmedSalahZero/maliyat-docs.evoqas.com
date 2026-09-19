<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Inventory Stock Ledger
//
//  The permanent, day-by-day moving-average record for every item
//  with inventory (trading, raw_material, product). One row per
//  item per CALENDAR DAY that had any movement — never per
//  transaction — because Option A prices a whole day's outbound
//  movements (sales, production consumption) at one blended
//  average built from that day's inbound movements (purchases,
//  production output) counted in first. See
//  MovingAverageCostingService for the full algorithm this table
//  supports.
//
//  This table is always rebuilt, never hand-edited: whenever a
//  sale, purchase, or production order affecting an item is
//  created, changed, or deleted, every ledger row for that item
//  from the affected date forward is deleted and recomputed from
//  scratch (see MovingAverageCostingService::recalculateItem()).
//  That is what keeps it honest with no "closed period" concept —
//  see the plan doc for why closing periods isn't an option here.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('item_id')
                  ->constrained('items')
                  ->cascadeOnDelete();
            $table->date('date');

            // Beginning balance = yesterday's ending balance for
            // this item (or 0/0 for the item's very first movement).
            $table->decimal('beginning_qty', 14, 2)->default(0);
            $table->decimal('beginning_value', 14, 2)->default(0);

            // Everything that came IN today (purchases + production
            // output for this item), counted in before anything
            // outbound today is priced.
            $table->decimal('qty_in', 14, 2)->default(0);
            $table->decimal('value_in', 14, 2)->default(0);

            // Today's recalculated average = (beginning_value +
            // value_in) / (beginning_qty + qty_in). Stored so a
            // report can read a day's price without redoing the
            // division.
            $table->decimal('average_cost', 14, 4)->default(0);

            // Everything that left today (sales + production
            // material consumption), priced at average_cost above.
            $table->decimal('qty_out', 14, 2)->default(0);
            $table->decimal('value_out', 14, 2)->default(0);

            // Ending balance = tomorrow's beginning balance.
            $table->decimal('ending_qty', 14, 2)->default(0);
            $table->decimal('ending_value', 14, 2)->default(0);

            $table->timestamps();

            // One row per item per day — recalculation always
            // deletes and rewrites a date range rather than
            // updating in place, so this also guards against ever
            // accidentally leaving two rows for the same day.
            $table->unique(['item_id', 'date']);
            $table->index(['company_id', 'item_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_ledger');
    }
};
