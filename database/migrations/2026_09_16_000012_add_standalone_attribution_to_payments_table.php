<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('payable_id')
                  ->constrained('customers')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('customer_id')
                  ->constrained('vendors')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('vendor_id')
                  ->constrained('categories')->nullOnDelete();
            $table->string('note')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('note');
        });
    }
};
