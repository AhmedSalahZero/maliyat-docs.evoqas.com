<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Production Orders ("Day Production")
//
//  One row per production run: "I made {qty} {product} today."
//  Raw materials consumed live in production_order_material_lines,
//  any extra costs (electricity, delivery, ...) live in
//  production_order_other_cost_lines. This table itself carries
//  the labor figure because there's exactly one per order (a total
//  for the batch), not a repeater.
//
//  material_cost / other_cost_total / total_cost / unit_cost are
//  all stored (not recalculated on the fly) so that later edits to
//  a raw material's average cost never silently rewrite what THIS
//  batch was actually costed at — same reasoning as
//  inventory_purchase_lines snapshotting its own UOM.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('item_id')
                  ->comment('The product made — an item with type=product')
                  ->constrained('items')
                  ->restrictOnDelete();
            $table->date('date');
            $table->decimal('qty_produced', 12, 2);

            $table->decimal('material_cost', 12, 2)->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('other_cost_total', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
