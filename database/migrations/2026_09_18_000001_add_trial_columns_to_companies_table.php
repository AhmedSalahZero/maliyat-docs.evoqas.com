<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Company Trial Window
//
//  Every company signs up on a free trial that runs for a fixed
//  number of months (config/subscription.php). When it lapses the
//  company can no longer sign in at all — see
//  User::accessDenialReason().
//
//  Two columns, each doing one job:
//    trial_ends_at       — the moment access stops. Null means "no
//                          expiry", which is how a company that has
//                          actually paid is marked.
//    expiry_notified_at  — when the "your trial is ending" email was
//                          last sent, so the daily command doesn't
//                          email the same company every morning for
//                          the whole final week.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('is_active');
            $table->timestamp('expiry_notified_at')->nullable()->after('trial_ends_at');
        });

        // Companies that already existed before trials were a thing
        // start their window now rather than being locked out on the
        // next request.
        $months = (int) config('subscription.trial_months', 2);

        Schema::getConnection()
            ->table('companies')
            ->whereNull('trial_ends_at')
            ->update(['trial_ends_at' => now()->addMonthsNoOverflow($months)]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'expiry_notified_at']);
        });
    }
};
