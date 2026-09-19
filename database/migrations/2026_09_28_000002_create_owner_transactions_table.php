<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Owner Transactions Table
//
//  "Receive {amount} from {owner}" / "Pay {amount} to {owner}" — a
//  single-step cash movement between the business and one of its
//  owners, no invoice/bill or given+settle two-step the way Sale/
//  Expense/Custody work, because there's nothing to invoice or
//  settle here: the money either came in or went out, in full, on
//  the date recorded.
//
//  `category` carries the finer distinction the direction alone
//  doesn't:
//    direction=in,  category=capital_injection  → fresh paid-in capital
//    direction=in,  category=repay_withdrawal   → owner returning money
//                                                  they'd previously
//                                                  withdrawn
//    direction=out, category=withdrawal         → owner drawing down
//                                                  their equity
//    direction=out, category=profit_distribution→ a share of profit
//                                                  paid out (the one
//                                                  category that also
//                                                  shows up as its own
//                                                  line on the P&L —
//                                                  see JournalService::
//                                                  postOwnerTransaction()
//                                                  and ReportDataService::
//                                                  profitAndLoss()).
//
//  capital_injection and repay_withdrawal post identically (both
//  credit the same equity account — see JournalService) and only
//  differ in how the Owner Statement labels them. Which categories
//  are valid for which direction is enforced in the FormRequest
//  (StoreOwnerTransactionRequest/UpdateOwnerTransactionRequest), not
//  as a DB-level CHECK — kept consistent with how the rest of this
//  schema validates enum-shaped business rules (e.g. Vendor.type)
//  rather than mixing the two.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('owner_id')
                  ->constrained('owners')
                  ->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->enum('category', ['capital_injection', 'repay_withdrawal', 'withdrawal', 'profit_distribution']);
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('cash');
            $table->foreignId('payment_channel_id')
                  ->nullable()
                  ->constrained('payment_channels')
                  ->nullOnDelete();
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_transactions');
    }
};
