<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Opening Balances Table
//
//  One row per company. This is only the CASH + BANK half of the
//  opening balance (the only two figures that don't already have a
//  real table of their own to live in) plus a status "lock":
//
//    draft  → the wizard is open, nothing posted yet.
//    posted → cash/bank + every customer/supplier/inventory/
//              equipment opening line has been journaled. The
//              wizard screen becomes read-only.
//
//  Customer, supplier, inventory and equipment opening balances do
//  NOT get their own rows here — they're stored as ordinary Sale /
//  Expense / InventoryPurchase / EquipmentPurchase records (flagged
//  is_opening_balance = true, see the next migration), so they show
//  up automatically in the customer/supplier statements, "All
//  Entries", Reports, and can be corrected later through the exact
//  same edit/delete screens as any real transaction — no separate
//  editing system had to be built for them.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->unique()
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->date('opening_date');
            $table->decimal('cash_amount', 12, 2)->default(0);
            $table->decimal('bank_amount', 12, 2)->default(0);
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
