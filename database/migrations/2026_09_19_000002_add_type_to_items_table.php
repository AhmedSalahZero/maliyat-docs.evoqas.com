<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Add Type to Items
//
//  type = 'trading'      → bought and sold as-is (unchanged behavior,
//                           this is what every existing item becomes).
//         'raw_material'  → only ever bought, then consumed by a
//                           Production Order. Never sold directly.
//         'product'       → only ever created by a Production Order,
//                           then sold normally. Never bought directly.
//
//  Defaulting every existing row to 'trading' means nothing about
//  a Trading-only or Service-only company changes.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('type')->default('trading')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
