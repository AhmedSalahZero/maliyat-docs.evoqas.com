<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Production Order Other Cost Lines: category_id
//
//  QA fix (Sep 2026): "Other expenses" on a Production Order used to
//  be a free-text description with no link to anything else in the
//  app — a line typed here could never be found again, compared
//  against, or reused when logging a normal Expense later.
//
//  category_id ties each line to the same Category records used
//  everywhere else expenses are categorized (Rent, Utilities,
//  Packaging, ...). `description` is kept and still populated — a
//  snapshot of the category's name at the moment the line was
//  created, the same pattern already used for
//  production_order_material_lines.unit_cost_snapshot — so a
//  historical line's label never changes retroactively if someone
//  renames the category later.
//
//  nullOnDelete rather than cascade: categories aren't expected to
//  be deleted in normal use, but if one ever is, the historical
//  production cost line should survive (its description snapshot
//  still says what it was for) rather than disappearing.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_other_cost_lines', function (Blueprint $table) {
            $table->foreignId('category_id')
                  ->nullable()
                  ->after('production_order_id')
                  ->constrained('categories')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_other_cost_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
