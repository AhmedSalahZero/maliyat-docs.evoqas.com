<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Production Labor fields on Expenses
//
//  is_production_labor: the "This is Production Labor" checkbox.
//  When checked, this expense is the REAL salary paid to production
//  workers, and gets reconciled against the labor-cost figures
//  already typed into that month's Production Orders — see
//  JournalService::postProductionLaborExpense().
//
//  production_labor_applied_snapshot: how much of that month's
//  "applied" labor (from Production Orders) THIS expense actually
//  cleared, recorded at posting time. Needed so that:
//    (a) a second payroll expense in the same month doesn't try to
//        clear labor that a previous one already cleared, and
//    (b) editing/deleting this expense can be reversed cleanly.
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_production_labor')->default(false)->after('category_id');
            $table->decimal('production_labor_applied_snapshot', 12, 2)->nullable()->after('is_production_labor');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['is_production_labor', 'production_labor_applied_snapshot']);
        });
    }
};
