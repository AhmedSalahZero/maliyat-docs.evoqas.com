<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Add Business Types to Companies
//
//  A company picks one or more of: trading, service, production.
//  Stored as a simple JSON array of strings — see
//  Company::businessTypes()/hasBusinessType(). Every EXISTING
//  company gets ['trading'] as its default (see the data backfill
//  below) so nothing about how they already use the app changes:
//  Inventory stays visible, no Production tab appears, no Item type
//  picker appears. This is purely additive.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('business_types')->nullable()->after('currency');
        });

        // Backfill: every company that already exists is "Trading"
        // by default — matches exactly how the app already behaved
        // for them before this feature existed.
        DB::table('companies')->whereNull('business_types')->update([
            'business_types' => json_encode(['trading','service','production']),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('business_types');
        });
    }
};
