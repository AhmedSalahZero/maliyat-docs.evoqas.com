<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — is_opening_balance flag
//
//  Added to every table that an opening-balance line reuses (Sale
//  for a customer owing money, Expense for a supplier owed money,
//  InventoryPurchase for a starting SKU quantity/value, Equipment-
//  Purchase for an already-owned asset, Payment for the starting
//  cash/bank amount). The row behaves exactly like a normal one
//  everywhere (statements, ledger, edit/delete) — this flag only:
//    1. Lets Reports label it "Opening Balance" instead of
//       "Sale #12" / "Expense #7" so it doesn't look like a
//       transaction that never happened.
//    2. Lets OpeningBalanceService find and reverse every opening
//       line in one pass if the whole thing needs redoing.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'expenses', 'inventory_purchases', 'equipment_purchases', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('is_opening_balance')->default(false)->after('amount');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales', 'expenses', 'inventory_purchases', 'equipment_purchases', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('is_opening_balance');
            });
        }
    }
};
