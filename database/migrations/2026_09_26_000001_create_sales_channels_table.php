<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Sales Channels Table
//  Where a sale actually came from — Direct, Delivery, an online
//  platform, a WhatsApp group, or anything the company adds itself.
//  Same shape as categories: an English `name` plus an optional
//  `name_ar`, so the 4 starter channels (seeded per company by
//  SalesChannel::seedDefaults()) show in Arabic automatically for
//  an Arabic-language user, the same way category names do — see
//  ComboSelect.vue's optionText().
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
