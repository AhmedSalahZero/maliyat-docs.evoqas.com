<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — one-time clean-up of existing names
//
//  Customers, suppliers/employees and team members saved before the
//  duplicate-name rule existed may carry spaces at the start or end
//  ("  Cairo Company "), or doubled spaces inside. This trims them so
//  the new rule compares like with like.
//
//  It only rewrites the NAME text. It never merges, deletes or
//  re-points anything, so no invoice, bill or payment is affected.
//  Two records that turn out to share a name are left as they are —
//  run `php artisan names:duplicates` to list them for review.
// ══════════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'vendors', 'users'] as $table) {
            DB::table($table)->select('id', 'name')->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $clean = \App\Support\NameNormalizer::clean($row->name);

                    if ($clean !== '' && $clean !== $row->name) {
                        DB::table($table)->where('id', $row->id)->update(['name' => $clean]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: the removed spaces carried no information.
    }
};
