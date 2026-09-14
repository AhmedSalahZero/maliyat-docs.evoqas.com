<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Custodies Table (عهدة)
//
//  Money handed to an employee (holder) to spend on the business's
//  behalf. Settlement lines (custody_settlement_lines) record what
//  it was actually spent on. leftover_returned / extra_reimbursed
//  are computed and stored at settlement time for fast reporting.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('holder_id')
                  ->constrained('vendors')
                  ->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('cash');
            $table->date('given_at');
            $table->boolean('settled')->default(false);
            $table->date('settlement_date')->nullable();
            $table->decimal('settlement_total', 12, 2)->default(0);
            $table->decimal('leftover_returned', 12, 2)->default(0);
            $table->decimal('extra_reimbursed', 12, 2)->default(0);
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'settled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custodies');
    }
};
