<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method_new', 20)->default('cash')->after('method');
        });

        DB::table('payments')->update(['method_new' => DB::raw('method')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('method_new', 'method');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method_old', ['cash', 'bank', 'visa'])->default('cash')->after('method');
        });

        DB::table('payments')->update(['method_old' => DB::raw("CASE WHEN method IN ('cash','bank','visa') THEN method ELSE 'cash' END")]);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('method_old', 'method');
        });
    }
};
