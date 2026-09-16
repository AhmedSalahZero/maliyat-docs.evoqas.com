<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Production Order Other Cost Lines
//
//  The "any other expense" repeater on a Production Order — e.g.
//  electricity, delivery. Assumed paid in cash on the day of
//  production (no due date / payment mode, unlike Expenses), which
//  is why this is its own small table rather than reusing Expense.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_other_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')
                  ->constrained('production_orders')
                  ->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_other_cost_lines');
    }
};
