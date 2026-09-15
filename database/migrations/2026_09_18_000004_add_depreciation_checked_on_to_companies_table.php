<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — depreciation_checked_on
//
//  Depreciation used to depend on the server's cron calling
//  Laravel's scheduler. If that one crontab line was missing there
//  was no error and no warning — depreciation simply never posted.
//
//  It is now driven by the app itself: PostDueDepreciation runs the
//  catch-up on the first page a company opens each day. This column
//  is what makes that cheap — it records the last calendar day the
//  check ran, so every other request that day skips it outright.
//
//  Deliberately a DATE, not a timestamp: the question being asked
//  is "have we looked today?", nothing finer.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('depreciation_checked_on')->nullable()->after('expiry_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('depreciation_checked_on');
        });
    }
};
