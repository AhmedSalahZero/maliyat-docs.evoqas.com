<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Sale Drafts Table
//
//  An unfinished sale, parked so nothing typed is lost.
//
//  Deliberately NOT a row in `sales` with a "draft" status: every
//  report, statement, dashboard figure, stock level and journal
//  query reads `sales`, and each of them would have had to learn to
//  skip drafts — miss one and a half-typed sale shows up in the
//  accounts. Keeping drafts in their own table means the accounting
//  side cannot see them at all. A draft only becomes a real sale
//  when somebody presses Record, which goes through the normal
//  SaleController::store() with its full validation.
//
//  `data` is the sale form as the user left it (customer, lines,
//  VAT, payment choice …), stored as-is, incomplete by design.
//  Shared by the whole company team (owner's decision, Sep 2026).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->json('data');
            $table->timestamps();

            $table->index(['company_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_drafts');
    }
};
