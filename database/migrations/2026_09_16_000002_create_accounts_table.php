<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Accounts Table (Chart of Accounts)
//
//  Every company gets a standard chart of accounts seeded when it's
//  created (see JournalService::seedChartOfAccounts()). `code` is
//  how the rest of the codebase refers to a standard account (e.g.
//  "1100" for Accounts Receivable) without hardcoding IDs;
//  category-linked expense accounts (one per expense Category —
//  Rent, Salaries, etc.) get an auto-generated code like
//  "EXP-{category_id}" instead of a number from the standard list.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('name_ar', 150)->nullable();
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
