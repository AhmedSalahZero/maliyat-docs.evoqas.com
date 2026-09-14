<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Journal Entries Table
//
//  One row per balanced double-entry posting. `source` links back
//  to whatever business event caused it (a Sale being invoiced, a
//  Payment being received, a Custody being settled, ...) purely for
//  traceability — "why does this entry exist". The actual debit/
//  credit lines live in journal_lines.
//
//  `reversed_by_id` / `reverses_id` implement voiding-by-reversal:
//  a wrong entry is never deleted or edited — a new entry with the
//  exact opposite debits/credits is posted against it, and both
//  stay in the ledger forever. That's what makes an audit trail an
//  audit trail.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->date('date');
            $table->string('memo', 255)->nullable();
            $table->nullableMorphs('source'); // source_type, source_id — e.g. Sale #42
            $table->foreignId('reverses_id')->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
