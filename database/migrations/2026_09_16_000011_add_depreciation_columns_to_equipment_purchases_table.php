<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_purchases', function (Blueprint $table) {
            $table->unsignedTinyInteger('useful_life_years')->default(5)->after('amount');
            $table->decimal('accumulated_depreciation', 12, 2)->default(0)->after('useful_life_years');
            $table->date('last_depreciated_through')->nullable()->after('accumulated_depreciation');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_purchases', function (Blueprint $table) {
            $table->dropColumn(['useful_life_years', 'accumulated_depreciation', 'last_depreciated_through']);
        });
    }
};
