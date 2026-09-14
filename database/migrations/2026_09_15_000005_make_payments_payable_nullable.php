<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY payable_type VARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE payments MODIFY payable_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
