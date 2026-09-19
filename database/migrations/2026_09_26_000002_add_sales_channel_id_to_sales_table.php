<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Nullable, same as payment_channel_id on payments —
            // every sale gets one in practice (the create form always
            // sends the company's default "Direct Sales" channel;
            // see SalesChannel::defaultChannel() and
            // SaleController::store()), but the column itself stays
            // optional so a channel can later be deleted without
            // being blocked by, or silently deleting, sales that
            // used it — nullOnDelete just clears the reference.
            $table->foreignId('sales_channel_id')->nullable()
                  ->after('customer_id')
                  ->constrained('sales_channels')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_channel_id');
        });
    }
};
