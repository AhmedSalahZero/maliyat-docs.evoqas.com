<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Add Owner Equity Accounts
//
//  Two new accounts backing the Owner Injection/Withdrawal feature:
//    3100  Owner Contributions & Withdrawals — capital paid in
//          (capital_injection, repay_withdrawal) net of capital
//          drawn out (withdrawal). This is what the Balance Sheet's
//          "net amount of Withdrawal statement" equity row reads —
//          see ReportDataService::balanceSheet(), which already
//          picks up every non-zero equity-type account with no
//          further code, so nothing there needed to change.
//    3200  Owner Profit Distributions — profit actually paid out to
//          owners. Also an equity account (so it shows on the
//          Balance Sheet the same automatic way), but ALSO read
//          explicitly by ReportDataService::profitAndLoss() to
//          produce the new "Net Profit after Owners' Profit Pay"
//          row — see JournalService::postOwnerTransaction().
//
//  New companies get these from JournalService::seedChartOfAccounts()
//  (Account::STANDARD_CODES) automatically. This backfills every
//  company that already existed before this migration, the same
//  approach as 2026_09_19_000001's business_types backfill —
//  inserted directly rather than through the service, since a
//  migration shouldn't depend on application services that may
//  themselves change shape later.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    private const NEW_ACCOUNTS = [
        Account::OWNER_CONTRIBUTIONS_WITHDRAWALS => ["Owner Contributions & Withdrawals", 'رأس مال وسحوبات الملاك', 'equity'],
        Account::OWNER_PROFIT_DISTRIBUTIONS       => ["Owner Profit Distributions",       'أرباح موزعة للملاك',    'equity'],
    ];

    public function up(): void
    {
        $companyIds = DB::table('companies')->pluck('id');
        $now = now();

        foreach ($companyIds as $companyId) {
            foreach (self::NEW_ACCOUNTS as $code => [$name, $nameAr, $type]) {
                $exists = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('accounts')->insert([
                    'company_id' => $companyId,
                    'code'       => $code,
                    'name'       => $name,
                    'name_ar'    => $nameAr,
                    'type'       => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('code', array_keys(self::NEW_ACCOUNTS))->delete();
    }
};
