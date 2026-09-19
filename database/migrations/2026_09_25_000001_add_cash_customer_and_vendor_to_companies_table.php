<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Cash Customer / Cash Vendor pointers on Companies
//
//  Cash Sales (Sales tab) and Cash Expense (Expense tab) create a
//  real Sale/Expense row — and sales.customer_id / expenses.vendor_id
//  are NOT NULL — but the whole point of "Cash Sales"/"Cash Expense"
//  is that the person doesn't pick a customer/vendor. So every
//  company gets one auto-created, reusable Customer named "Cash
//  Customer" and one Vendor named "Cash Vendor" that every cash
//  sale/expense is quietly filed under (see
//  Customer::cashCustomer()/Vendor::cashVendor()) — the person is
//  never asked to choose one.
//
//  These columns point at that specific row (rather than looking it
//  up by name each time) so that renaming "Cash Customer" — same
//  pencil-icon rename any customer has — doesn't cause a second one
//  to be created next time. No foreign key constraint on purpose:
//  customers/vendors already point back at companies, and this is
//  only ever read by the app to resolve one specific row, not
//  something the database needs to enforce.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_customer_id')->nullable()->after('created_by');
            $table->unsignedBigInteger('cash_vendor_id')->nullable()->after('cash_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cash_customer_id', 'cash_vendor_id']);
        });
    }
};
