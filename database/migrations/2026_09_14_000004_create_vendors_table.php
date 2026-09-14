<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Vendors Table
//
//  Doubles as the "Vendor / Employee" list used across Expenses,
//  Inventory Purchase, Equipment, and Custody — matches the
//  prototype's combined combo-box design. `type` just labels which
//  one it was first added as; both are pickable everywhere.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['vendor', 'employee'])->default('vendor');
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
