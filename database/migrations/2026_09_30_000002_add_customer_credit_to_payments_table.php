<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Customer credit (audit finding 4.6).
//
//  Marks a receipt that created a CREDIT for a customer — money they
//  paid in advance or paid over an invoice — instead of revenue. It
//  is booked to Customer Credits (account 2300, a liability: the
//  business owes it back in goods, services or cash) and used up
//  later against that customer's invoices (a payment with method
//  'credit'). See App\Services\CustomerCreditService.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_customer_credit')->default(false)->after('is_opening_balance');
        });

        // Give every existing company the new Customer Credits account
        // (new companies get it from Account::STANDARD_CODES) — same
        // pattern as 2026_09_28_000003_add_owner_equity_accounts.
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', '2300')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('accounts')->insert([
                'company_id' => $companyId,
                'code'       => '2300',
                'name'       => 'Customer Credits (Advances)',
                'name_ar'    => 'أرصدة دائنة للعملاء (دفعات مقدمة)',
                'type'       => 'liability',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '2300')->delete();

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('is_customer_credit');
        });
    }
};
