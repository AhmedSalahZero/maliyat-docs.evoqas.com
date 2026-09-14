<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Items Table
//
//  uom / qty_per_uom / base_unit_name store the *last used* purchase
//  unit definition for this item (e.g. "Carton" = 24 "unit", or
//  "Carton" = 12 "Bottles" for olive oil) — used as a prefill when
//  starting a new purchase line. Each purchase line stores its own
//  snapshot too, so historical stock math never breaks if this
//  changes later.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('uom')->default('Carton');
            $table->decimal('qty_per_uom', 12, 2)->default(1);
            $table->string('base_unit_name')->default('unit');
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
