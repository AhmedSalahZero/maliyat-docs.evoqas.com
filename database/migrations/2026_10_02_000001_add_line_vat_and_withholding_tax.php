<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — VAT and Withholding Tax per product line
//
//  Sales and Inventory Purchases used to carry ONE VAT % for the whole
//  invoice. Now every product line has its own:
//
//    vat_rate / vat_amount                  — VAT on that line
//    withholding_rate / withholding_amount  — Withholding Tax on that
//        line, calculated on the line amount BEFORE VAT
//
//  and each invoice header records the total withholding:
//
//    sales.withholding_amount               — DEBIT withholding: the
//        customer keeps this part and pays it to the tax authority
//        for us (an asset for us).
//    inventory_purchases.withholding_amount — CREDIT withholding: we
//        keep this part of what we owe the supplier and pay it to the
//        tax authority (a liability for us).
//
//  IMPORTANT — what "amount" means from now on:
//    amount = subtotal + VAT - withholding  = the money that is
//    actually owed (by the customer / to the supplier). Because every
//    balance, payment and open-invoice check in the app already works
//    from "amount", they all keep working without any change.
//    For every invoice that existed before this migration the
//    withholding is 0, so "amount" is exactly what it always was.
//
//  Existing lines are filled in from their invoice's old single VAT %
//  so that editing an old invoice shows the same VAT it always had.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sale_lines', 'inventory_purchase_lines'] as $table) {
            if (Schema::hasColumn($table, 'withholding_amount')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->decimal('vat_rate', 5, 2)->default(0)->after('line_total');
                $t->decimal('vat_amount', 12, 2)->default(0)->after('vat_rate');
                $t->decimal('withholding_rate', 5, 2)->default(0)->after('vat_amount');
                $t->decimal('withholding_amount', 12, 2)->default(0)->after('withholding_rate');
            });
        }

        foreach (['sales', 'inventory_purchases'] as $table) {
            if (Schema::hasColumn($table, 'withholding_amount')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->decimal('withholding_amount', 12, 2)->default(0)->after('vat_amount');
            });
        }

        $this->backfillLines('sales', 'sale_lines', 'sale_id');
        $this->backfillLines('inventory_purchases', 'inventory_purchase_lines', 'inventory_purchase_id');
    }

    /**
     * Copy the invoice's old single VAT % onto each of its lines.
     */
    private function backfillLines(string $headerTable, string $lineTable, string $foreignKey): void
    {
        DB::table($headerTable)
            ->where('vat_rate', '>', 0)
            ->chunkById(200, function ($headers) use ($lineTable, $foreignKey) {
                foreach ($headers as $header) {
                    $lines = DB::table($lineTable)->where($foreignKey, $header->id)->get(['id', 'line_total']);

                    foreach ($lines as $line) {
                        DB::table($lineTable)->where('id', $line->id)->update([
                            'vat_rate'   => $header->vat_rate,
                            'vat_amount' => round((float) $line->line_total * (float) $header->vat_rate / 100, 2),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        foreach (['sale_lines', 'inventory_purchase_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['vat_rate', 'vat_amount', 'withholding_rate', 'withholding_amount']);
            });
        }

        foreach (['sales', 'inventory_purchases'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('withholding_amount');
            });
        }
    }
};
