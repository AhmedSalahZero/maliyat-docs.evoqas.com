<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — stock ledger quantities to 4 decimals (audit M9)
//
//  The daily stock ledger kept quantities to 2 decimals, so anything
//  bought or sold in part-units (3 cartons of 0.333 kg, 1.5 × 0.75)
//  lost a little every time and the quantity on hand slowly drifted.
//  MovingAverageCostingService now works to 4 decimals; these columns
//  hold that. Money columns are unchanged (cents).
//
//  Existing rows keep their values; the next change to an item
//  rebuilds its ledger from that date at the new precision.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    private const COLUMNS = ['beginning_qty', 'qty_in', 'qty_out', 'ending_qty'];

    public function up(): void
    {
        Schema::table('inventory_stock_ledger', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 18, 4)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_ledger', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 14, 2)->default(0)->change();
            }
        });
    }
};
