<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Add Withholding Tax Accounts
//
//  Two new accounts for the Withholding Tax feature:
//    1160  Withholding Tax Receivable (asset) — DEBIT withholding on
//          sales: tax our customers withheld and paid to the tax
//          authority on our behalf. We can claim it back.
//    2150  Withholding Tax Payable (liability) — CREDIT withholding
//          on purchases: tax we withheld from our suppliers and
//          must pay to the tax authority.
//
//  New companies get these from Account::STANDARD_CODES. This
//  backfills every company that already exists, the same way
//  2026_09_28_000003 did for the owner equity accounts.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    private const NEW_ACCOUNTS = [
        Account::WITHHOLDING_TAX_RECEIVABLE => ['Withholding Tax Receivable (Debit)', 'ضريبة خصم من المنبع مستحقة (مدينة)', 'asset'],
        Account::WITHHOLDING_TAX_PAYABLE    => ['Withholding Tax Payable (Credit)',   'ضريبة خصم من المنبع مستحقة الدفع (دائنة)', 'liability'],
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
