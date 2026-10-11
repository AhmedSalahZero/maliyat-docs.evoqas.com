<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Quantity returns
//
//  A SALES RETURN is goods a customer sends back after a sale; a
//  PURCHASE RETURN is goods we send back to a supplier after a
//  purchase. Each return belongs to ONE original sale / purchase and
//  has its own date (a return a month later is dated a month later —
//  earlier months are never rewritten) and its own lines (which
//  original line, how many).
//
//  amounts          subtotal / VAT / withholding / amount are the
//                   returned share of the original line values, in
//                   the same shape as the original document
//                   (amount = subtotal + VAT - withholding).
//  applied_amount   the part of `amount` that simply reduces what is
//                   still owed on the original invoice/bill.
//  refund_amount    the rest (the customer/supplier had already
//                   paid): returned as cash/bank, or kept as credit
//                   on the customer's account (refund_method
//                   'credit').  amount = applied_amount + refund_amount.
//  unit_cost        COST PER BASE UNIT of the returned goods:
//                   a sales return puts the goods back at the cost
//                   the original sale took them out at; a purchase
//                   return takes them out at the original purchase
//                   price. See MovingAverageCostingService.
//
//  sales.returns_applied / inventory_purchases.returns_applied keep
//  the running total of applied_amount so every "what is still owed"
//  test (including the raw-SQL ones on the dashboard and worklists)
//  can read one column.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('date');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('withholding_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('refund_method', 20)->nullable();
            $table->foreignId('payment_channel_id')->nullable()->constrained('payment_channels')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('sale_lines')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('qty', 12, 2);
            $table->string('uom')->nullable();
            $table->decimal('qty_per_uom', 12, 2)->default(1);
            $table->string('base_unit_name')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('withholding_amount', 12, 2)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('inventory_purchase_id')->constrained('inventory_purchases')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->date('date');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('withholding_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('refund_method', 20)->nullable();
            $table->foreignId('payment_channel_id')->nullable()->constrained('payment_channels')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('inventory_purchase_line_id')->constrained('inventory_purchase_lines')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('qty', 12, 2);
            $table->string('uom')->nullable();
            $table->decimal('qty_per_uom', 12, 2)->default(1);
            $table->string('base_unit_name')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('withholding_amount', 12, 2)->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('returns_applied', 12, 2)->default(0)->after('amount');
        });

        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->decimal('returns_applied', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchases', fn (Blueprint $table) => $table->dropColumn('returns_applied'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('returns_applied'));
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
