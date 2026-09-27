<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — default sales channel pointer on Companies
//
//  Every new sale defaults to the company's "Direct Sales" channel.
//  That channel used to be found by its English NAME, so renaming or
//  translating it made the app lose track of it — and quietly create
//  a second "Direct Sales" (audit finding M11). This column points at
//  the specific row instead, the same way cash_customer_id does for
//  the Cash Customer. No foreign key, for the same reason given in
//  that migration.
//
//  Backfill: each existing company's "Direct Sales" row, or its
//  oldest channel if that name is no longer there.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('default_sales_channel_id')->nullable();
        });

        DB::table('companies')->orderBy('id')->pluck('id')->each(function ($companyId) {
            $channelId = DB::table('sales_channels')->where('company_id', $companyId)->where('name', 'Direct Sales')->min('id')
                ?? DB::table('sales_channels')->where('company_id', $companyId)->min('id');

            if ($channelId) {
                DB::table('companies')->where('id', $companyId)->update(['default_sales_channel_id' => $channelId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_sales_channel_id');
        });
    }
};
