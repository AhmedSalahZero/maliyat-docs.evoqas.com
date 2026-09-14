<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Installments Table (polymorphic)
//
//  The 4th payment mode from ledger-prototype-v8.html ("Installment"
//  — split the total into N scheduled payments every X days).
//  Same shape as the payments table's morph pair, so it works
//  identically for Sale, Expense, InventoryPurchase, and
//  EquipmentPurchase — Custody doesn't get this mode, it isn't a
//  bill with payment terms.
//
//  IMPORTANT — this is a SCHEDULE, not a ledger of money moved:
//  each row is "installment #N of this plan is due on this date for
//  this amount", purely for display (e.g. "3× EGP 500 every 30
//  days, next due 12 Oct"). It intentionally has no `paid` column
//  and no link to a specific payments row — money actually received
//  or paid is still recorded the normal way in the payments table
//  and reduces the parent's balance() overall, exactly like a
//  partial payment does today. This mirrors the prototype's own
//  behavior: it never reconciles a payment against one specific
//  installment either, it just shows the plan alongside the running
//  balance. Reconciling individual installments is a bigger, separate
//  feature if it's ever wanted.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->morphs('payable'); // payable_type, payable_id
            $table->unsignedSmallInteger('sequence'); // 1-based position in the plan
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
