<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Company fields on Users
//
//  Reuses the existing `role` string column (previously admin|member)
//  with new values: super_admin | company_admin | employee.
//    - super_admin  → company_id is null, can see/manage all companies
//    - company_admin → belongs to one company, can create employees
//    - employee      → belongs to one company, created by an admin
//
//  created_by tracks which user created this account (audit trail).
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('companies')
                  ->nullOnDelete();

            $table->foreignId('created_by')
                  ->nullable()
                  ->after('company_id')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
