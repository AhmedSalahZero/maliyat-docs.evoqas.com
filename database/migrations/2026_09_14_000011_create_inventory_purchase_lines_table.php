<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Inventory Purchase Lines Table
//
//  uom / qty_per_uom / base_unit_name are snapshotted PER LINE
//  (not just read from items) so historical stock and average-cost
//  math stays correct even if the item's default UOM changes later.
//  Stock added by this line, in base units = qty * qty_per_uom.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_purchase_id')
                  ->constrained('inventory_purchases')
                  ->cascadeOnDelete();
            $table->foreignId('item_id')
                  ->constrained('items')
                  ->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->string('uom');
            $table->decimal('qty_per_uom', 12, 2)->default(1);
            $table->string('base_unit_name')->default('unit');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_lines');
    }
};
