<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Sales Table
//  One row per sale/invoice. Line items live in sale_lines.
//  amount = subtotal + vat_amount (VAT-inclusive total).
//  Balance owed = amount − sum(payments.amount) via the polymorphic
//  payments table. due_date is only meaningful while balance > 0.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('customer_id')
                  ->constrained('customers')
                  ->restrictOnDelete();
            $table->date('date');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
