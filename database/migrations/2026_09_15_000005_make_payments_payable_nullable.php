<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Nullable payable_* on payments
//
//  payments.payable_type / payable_id were created via
//  Blueprint::morphs() (NOT NULL) in the original migration. But
//  "Or log money with no invoice/bill" is a real, intentional
//  feature (see the prototype's orLogGeneric flow and
//  PaymentController::storeReceipt()/storePayment()) — a payment
//  with no payable at all must be allowed.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY payable_type VARCHAR(255) NULL');
            DB::statement('ALTER TABLE payments MODIFY payable_id BIGINT UNSIGNED NULL');

            return;
        }

        // Every other database (SQLite — the one in .env.example and
        // the one the test suite runs on — PostgreSQL, ...). This used
        // to do nothing at all off MySQL, so a payment with no invoice
        // behind it (opening cash/bank, a receipt from a customer, a
        // loose payment) failed with "NOT NULL constraint failed:
        // payments.payable_type" and the page returned a server error.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payable_type')->nullable()->change();
            $table->unsignedBigInteger('payable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY payable_type VARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE payments MODIFY payable_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
