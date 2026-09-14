<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Expenses Table
//
//  vendor_id points at the vendors table, which also holds
//  "employee" rows — covers salaries paid through Expenses.
//
//  recurring_* columns identify one occurrence of a recurring
//  series (e.g. monthly rent). recurring_id groups the occurrences;
//  each occurrence is still its own independent, settleable row.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->foreignId('vendor_id')
                  ->constrained('vendors')
                  ->restrictOnDelete();
            $table->foreignId('category_id')
                  ->constrained('categories')
                  ->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2);
            $table->date('due_date')->nullable();

            $table->uuid('recurring_id')->nullable();
            $table->unsignedSmallInteger('recurring_index')->nullable();
            $table->unsignedSmallInteger('recurring_count')->nullable();
            $table->enum('recurring_frequency', ['weekly', 'monthly', 'q3', 'h6'])->nullable();

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index('recurring_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
