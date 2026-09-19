<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — unit_cost on Sale Lines
//
//  The moving-average cost per base unit THIS LINE was actually
//  priced at, at the moment MovingAverageCostingService last priced
//  it — same idea as production_order_material_lines.unit_cost_snapshot,
//  which already exists. Locking this in per line, rather than
//  deriving it after the fact, is what lets the P&L show real
//  product-level Cost of Goods Sold without ever recalculating a
//  historical figure from today's data.
//
//  Null for a line with no item (a free-text/service line — never
//  has a cost) and, until the recalculation engine has priced it,
//  for a line entered before this column existed.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
