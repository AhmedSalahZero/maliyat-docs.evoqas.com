<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Production Order Material Lines
//
//  One row per raw material consumed by a Production Order (the
//  "raw materials used" repeater). qty is in the material's BASE
//  unit (same convention as sale_lines.qty), so Item::currentStock()
//  can subtract it exactly like a sale. unit_cost_snapshot is the
//  raw material's weighted-average cost AT THE TIME of this
//  production run — stored, not recalculated later, for the same
//  reason production_orders stores its own totals.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_material_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')
                  ->constrained('production_orders')
                  ->cascadeOnDelete();
            $table->foreignId('item_id')
                  ->comment('The raw material consumed — an item with type=raw_material')
                  ->constrained('items')
                  ->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('unit_cost_snapshot', 12, 4)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_material_lines');
    }
};
