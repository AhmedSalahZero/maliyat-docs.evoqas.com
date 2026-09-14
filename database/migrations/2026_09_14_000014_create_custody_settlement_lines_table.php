<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Custody Settlement Lines Table
//  What a custody amount was actually spent on. Each line also
//  feeds the normal Expenses-by-category P&L breakdown.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_id')
                  ->constrained('custodies')
                  ->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('categories')
                  ->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_settlement_lines');
    }
};
