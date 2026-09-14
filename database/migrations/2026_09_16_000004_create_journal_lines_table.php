<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Journal Lines Table
//
//  One row per debit or credit within a journal entry (an entry
//  normally has 2-4 lines). `company_id` is denormalized here too
//  (not just reachable via the parent entry) so trial-balance/
//  account-ledger queries can filter and group directly on this
//  table without a join, the same reasoning as payments.company_id.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('journal_entry_id')
                  ->constrained('journal_entries')
                  ->cascadeOnDelete();
            $table->foreignId('account_id')
                  ->constrained('accounts')
                  ->cascadeOnDelete();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
