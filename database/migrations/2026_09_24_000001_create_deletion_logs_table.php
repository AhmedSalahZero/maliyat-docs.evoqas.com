<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Deletion Logs Table
//  Location: database/migrations/2026_09_24_000001_create_deletion_logs_table.php
//
//  QA audit (Sep 2026) finding: deleting a sale/expense/payment/etc.
//  removed the row completely with no record anywhere of who did it
//  or what it contained. For a bookkeeping product that is a real
//  internal-control gap — there was no way to answer "who deleted
//  this invoice, and what did it say?" after the fact.
//
//  This table is the fix: every destroy() in the App controllers
//  writes one row here, in the SAME database transaction as the
//  delete itself, before the row is actually removed. It is
//  deliberately NOT a soft-delete (the app's tables, foreign keys,
//  and existing balance()/report queries all assume a deleted row
//  is simply gone) — this is an audit trail sitting alongside the
//  real tables, not a replacement for deleting.
//
//  `payload` is a JSON snapshot of the record as it existed the
//  moment before deletion, so "what did it say" is answerable
//  without needing to reconstruct it from the (now also reversed)
//  ledger entries.
//
//  Not scoped by BelongsToCompany on purpose: a company_admin
//  should not be able to make their own deletions invisible to a
//  platform admin investigating a dispute, so this table is read
//  through its own explicit company_id filter wherever it's
//  queried from company-side code, and left open for the platform
//  admin area.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Who actually pressed delete. Nullable + set-null so a
            // later, unrelated cleanup of the users table never
            // breaks — the log itself must survive even if the user
            // account behind it doesn't.
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // e.g. "App\Models\Sale" — kept as the fully-qualified
            // class name (not a short label) so it can never
            // collide with another model and is trivial to grep
            // for or join back against, the same convention the
            // payments table already uses for payable_type.
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            // A short, human-readable line for a log list — e.g.
            // "Sale #482 — Acme Trading — 4,500.00 EGP" — so a
            // platform admin doesn't have to decode the JSON just
            // to scan the list.
            $table->string('summary');

            // Full snapshot of the record (and, where useful, its
            // direct child rows — e.g. a sale's lines) as it stood
            // immediately before deletion.
            $table->json('payload');

            $table->timestamp('deleted_at')->useCurrent();

            $table->index(['company_id', 'deleted_at']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
    }
};
