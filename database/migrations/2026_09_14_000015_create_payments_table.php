<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Payments Table (polymorphic)
//
//  Every actual money movement — receiving on a sale, paying a
//  bill, an installment, a custody hand-out or its leftover
//  refund — is one row here. This single table powers Cash Flow
//  and lets balanceOf($model) = amount - sum(payments) work the
//  same way for sales, expenses, inventory_purchases,
//  equipment_purchases, and custodies alike.
//
//  direction: 'in' (money received) or 'out' (money paid).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->morphs('payable'); // payable_type, payable_id
            $table->date('date');
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'bank', 'visa'])->default('cash');
            $table->enum('direction', ['in', 'out']);
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
