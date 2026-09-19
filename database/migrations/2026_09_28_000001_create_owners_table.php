<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Owners Table
//
//  Who a company's capital belongs to — deliberately its own table
//  rather than another Vendor `type`, the way Vendor already covers
//  vendor/employee: an owner isn't paid for goods or work the way a
//  vendor is, and mixing them would put owners into the Expense/
//  Supplier-Statement vendor dropdown, which is exactly the mixing
//  a coder using this app asked us to avoid. Scoped to one company
//  each, same as Customer/Vendor.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
